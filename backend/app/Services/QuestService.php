<?php

namespace App\Services;

use App\Enums\QuestType;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\QuestAnswer;
use App\Models\User;
use App\Services\Vision\FaceMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Gamification « quêtes » : génère la prochaine question de complétion de
 * données pour un utilisateur, en ciblant les personnes de son cercle proche
 * (GenealogyService::proximity) et ses propres médias. Ne propose jamais une
 * question que l'utilisateur ne pourrait pas appliquer (cf. matrice
 * d'autorisations dans QuestAnswerApplier).
 */
class QuestService
{
    /** Distance de parenté maximale du « cercle proche ». */
    private const CIRCLE_MAX_DISTANCE = 4;

    /** Taille maximale du cercle (et du repli par degré). */
    private const CIRCLE_MAX_SIZE = 40;

    /** TTL du lot de candidats en cache (secondes). */
    private const BATCH_TTL = 300;

    /** « Je ne sais pas » masque la question pour CE user pendant N jours. */
    private const DONT_KNOW_TTL_DAYS = 180;

    /** « Passer » masque la question pour CE user pendant N jours. */
    private const SKIPPED_TTL_DAYS = 7;

    /** Limite de candidats par détecteur média/visage. */
    private const DETECTOR_LIMIT = 10;

    /** Poids relatifs des types (les plus gratifiants d'abord). */
    private const WEIGHTS = [
        'face_identify' => 5,
        'birth_date' => 4,
        'gender' => 4,
        'media_date' => 3,
        'media_geo' => 3,
        'death_status' => 3,
        'marital_status' => 3,
    ];

    public function __construct(
        private GenealogyService $genealogy,
        private FaceMatcher $faceMatcher,
    ) {
    }

    /**
     * Prochaine question présentée (hydratée pour le front), ou null si le
     * lot est épuisé. Re-vérifie que le manque existe toujours au moment de
     * présenter (une réponse d'un autre compte a pu le combler).
     */
    public function next(User $user): ?array
    {
        foreach ($this->candidates($user) as $candidate) {
            $type = QuestType::from($candidate['type']);
            $subject = $type->subjectClass()::find($candidate['subject_id']);

            if ($subject && $this->gapExists($type, $subject)) {
                return $this->present($user, $type, $subject);
            }
        }

        return null;
    }

    /** Compteur affiché : nombre de réponses réellement apportées. */
    public function completedCount(User $user): int
    {
        return QuestAnswer::where('user_id', $user->id)
            ->where('answer_kind', 'answered')
            ->count();
    }

    /** Invalide le lot en cache (après chaque réponse). */
    public function forget(User $user): void
    {
        Cache::forget($this->cacheKey($user));
    }

    /**
     * Le manque visé par la question existe-t-il toujours ? Partagé entre la
     * présentation (next) et l'application (QuestAnswerApplier, anti-course).
     */
    public function gapExists(QuestType $type, Model $subject): bool
    {
        return match ($type) {
            QuestType::BirthDate => $subject->birth_date === null,
            QuestType::BirthPlace => $subject->birth_place === null,
            QuestType::DeathStatus, QuestType::DeathDateOld => $subject->death_date === null,
            QuestType::DeathPlace => $subject->death_date !== null && $subject->death_place === null,
            QuestType::Gender => $subject->gender === 'U',
            QuestType::ParentFather => $subject->father_id === null,
            QuestType::ParentMother => $subject->mother_id === null,
            QuestType::MaritalStatus => ! $this->hasRelationship($subject->id),
            QuestType::MaidenName => $subject->maiden_name === null,
            QuestType::Job => ! $subject->lifeEvents()->where('type', 'job')->exists(),
            QuestType::Education => ! $subject->lifeEvents()->whereIn('type', ['education', 'diplome'])->exists(),
            QuestType::Residence => ! $subject->lifeEvents()->where('type', 'residence')->exists(),
            QuestType::FaceIdentify => $subject->status === 'unmatched' && $subject->media !== null,
            QuestType::MediaDate => $subject->taken_at === null,
            QuestType::MediaGeo => $subject->metadata()->whereNotNull('latitude')->doesntExist(),
        };
    }

    /**
     * Lot ordonné de candidats [{type, subject_id, distance}], en cache court
     * par utilisateur. Seuls des ids y sont stockés — l'hydratation et la
     * re-vérification du manque se font à la présentation. Public : les tests
     * de génération s'appuient sur le CONTENU du lot (l'ordre est mélangé).
     */
    public function candidates(User $user): array
    {
        return Cache::remember($this->cacheKey($user), self::BATCH_TTL, function () use ($user) {
            $circle = $this->innerCircle($user);

            $candidates = array_merge(
                $this->detectPersonGaps($circle),
                $this->detectFaceGaps($user),
                $this->detectMediaGaps($user),
            );

            $candidates = $this->filterExcluded($user, $candidates);

            return $this->order($user, $candidates);
        });
    }

    private function cacheKey(User $user): string
    {
        return "quests:batch:{$user->id}";
    }

    /**
     * Cercle proche : distance de parenté ≤ 4 depuis la fiche « moi ».
     * Repli sans fiche « moi » : les personnes les plus connectées (degré),
     * avec une distance neutre pour la pondération.
     *
     * @return array<string,int> person_id => distance
     */
    private function innerCircle(User $user): array
    {
        ['distance' => $distance, 'degree' => $degree] = $this->genealogy->proximity($user->person_id);

        if ($distance !== []) {
            $circle = array_filter($distance, fn ($d) => $d <= self::CIRCLE_MAX_DISTANCE);
            asort($circle);

            return array_slice($circle, 0, self::CIRCLE_MAX_SIZE, true);
        }

        arsort($degree);

        return array_map(fn () => 2, array_slice($degree, 0, self::CIRCLE_MAX_SIZE, true));
    }

    /**
     * Manques sur les fiches du cercle : UNE requête people + une par table
     * annexe (life_events groupés, relations), puis analyse en mémoire.
     */
    private function detectPersonGaps(array $circle): array
    {
        if ($circle === []) {
            return [];
        }

        $ids = array_keys($circle);

        $people = Person::whereIn('id', $ids)->get([
            'id', 'gender', 'birth_date', 'birth_place', 'death_date',
            'death_place', 'maiden_name', 'father_id', 'mother_id',
        ]);

        // Personnes ayant au moins une union (statut marital + nom de naissance).
        $related = DB::table('person_relationships')
            ->whereIn('person1_id', $ids)
            ->orWhereIn('person2_id', $ids)
            ->get(['person1_id', 'person2_id']);
        $hasRelationship = [];
        foreach ($related as $rel) {
            $hasRelationship[$rel->person1_id] = true;
            $hasRelationship[$rel->person2_id] = true;
        }

        // Types de moments déjà renseignés par personne.
        $eventTypes = [];
        $events = DB::table('life_events')
            ->whereIn('person_id', $ids)
            ->whereIn('type', ['job', 'education', 'diplome', 'residence'])
            ->get(['person_id', 'type']);
        foreach ($events as $ev) {
            $eventTypes[$ev->person_id][$ev->type === 'diplome' ? 'education' : $ev->type] = true;
        }

        $candidates = [];
        $push = function (QuestType $type, string $personId) use (&$candidates, $circle) {
            $candidates[] = [
                'type' => $type->value,
                'subject_id' => $personId,
                'distance' => $circle[$personId] ?? 2,
            ];
        };

        foreach ($people as $p) {
            $age = $p->birth_date?->diffInYears(now());

            if ($p->birth_date === null) {
                $push(QuestType::BirthDate, $p->id);
            }
            if ($p->birth_place === null) {
                $push(QuestType::BirthPlace, $p->id);
            }
            if ($p->death_date === null && $age !== null) {
                // « Encore en vie ? » seulement quand la question a du sens ;
                // au-delà de 120 ans (GEDCOM) on demande directement la date.
                if ($age >= 70 && $age <= 120) {
                    $push(QuestType::DeathStatus, $p->id);
                } elseif ($age > 120) {
                    $push(QuestType::DeathDateOld, $p->id);
                }
            }
            if ($p->death_date !== null && $p->death_place === null) {
                $push(QuestType::DeathPlace, $p->id);
            }
            if ($p->gender === 'U') {
                $push(QuestType::Gender, $p->id);
            }
            if ($p->father_id === null) {
                $push(QuestType::ParentFather, $p->id);
            }
            if ($p->mother_id === null) {
                $push(QuestType::ParentMother, $p->id);
            }
            if (! isset($hasRelationship[$p->id]) && ($age === null || $age >= 18)) {
                $push(QuestType::MaritalStatus, $p->id);
            }
            if ($p->gender === 'F' && $p->maiden_name === null && isset($hasRelationship[$p->id])) {
                $push(QuestType::MaidenName, $p->id);
            }
            if ($age === null || $age >= 16) {
                foreach (['job' => QuestType::Job, 'education' => QuestType::Education, 'residence' => QuestType::Residence] as $kind => $type) {
                    if (! isset($eventTypes[$p->id][$kind])) {
                        $push($type, $p->id);
                    }
                }
            }
        }

        return $candidates;
    }

    /** Visages non identifiés sur des photos que l'utilisateur peut voir. */
    private function detectFaceGaps(User $user): array
    {
        return DetectedFace::where('status', 'unmatched')
            ->whereHas('media', fn ($q) => $q->accessibleBy($user)->where('type', 'photo'))
            ->latest()
            ->limit(self::DETECTOR_LIMIT)
            ->pluck('id')
            ->map(fn ($id) => ['type' => QuestType::FaceIdentify->value, 'subject_id' => $id, 'distance' => 1])
            ->all();
    }

    /** Photos du user sans date ou sans géolocalisation (owner-only : seules
     * ses propres photos sont modifiables, cf. MediaController::update). */
    private function detectMediaGaps(User $user): array
    {
        $undated = Media::where('user_id', $user->id)
            ->where('type', 'photo')
            ->whereNull('taken_at')
            ->latest()
            ->limit(self::DETECTOR_LIMIT)
            ->pluck('id')
            ->map(fn ($id) => ['type' => QuestType::MediaDate->value, 'subject_id' => $id, 'distance' => 1]);

        $unlocated = Media::where('user_id', $user->id)
            ->where('type', 'photo')
            ->whereDoesntHave('metadata', fn ($q) => $q->whereNotNull('latitude'))
            ->latest()
            ->limit(self::DETECTOR_LIMIT)
            ->pluck('id')
            ->map(fn ($id) => ['type' => QuestType::MediaGeo->value, 'subject_id' => $id, 'distance' => 1]);

        return $undated->concat($unlocated)->all();
    }

    /**
     * Retire les questions éteintes par le journal : « answered » et « non »
     * éteignent pour tout le monde (TTL éventuel sur « non »), « je ne sais
     * pas » et « passer » pour ce seul utilisateur (avec expiration).
     */
    private function filterExcluded(User $user, array $candidates): array
    {
        if ($candidates === []) {
            return [];
        }

        $keys = array_map(
            fn ($c) => QuestType::from($c['type'])->key($c['subject_id']),
            $candidates,
        );

        $answers = QuestAnswer::whereIn('question_key', array_unique($keys))
            ->get(['question_key', 'user_id', 'answer_kind', 'question_type', 'created_at']);

        $excluded = [];
        foreach ($answers as $a) {
            $globallyExcluded = match ($a->answer_kind) {
                'answered' => true,
                'no' => ($ttl = QuestType::from($a->question_type)->noAnswerTtlDays()) === null
                    || $a->created_at->gt(now()->subDays($ttl)),
                default => false,
            };

            $mineExcluded = $a->user_id === $user->id && match ($a->answer_kind) {
                'dont_know' => $a->created_at->gt(now()->subDays(self::DONT_KNOW_TTL_DAYS)),
                'skipped' => $a->created_at->gt(now()->subDays(self::SKIPPED_TTL_DAYS)),
                default => false,
            };

            if ($globallyExcluded || $mineExcluded) {
                $excluded[$a->question_key] = true;
            }
        }

        return array_values(array_filter(
            $candidates,
            fn ($c, $i) => ! isset($excluded[$keys[$i]]),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /**
     * Mélange pondéré, stable sur la journée (même graine que la « personne
     * du jour ») : poids du type / (1 + distance de parenté) + bruit, puis
     * passe de diversité — jamais deux questions de suite sur la même
     * personne, jamais trois de suite du même type.
     */
    private function order(User $user, array $candidates): array
    {
        mt_srand(crc32(now()->toDateString().$user->id));

        foreach ($candidates as &$c) {
            $weight = self::WEIGHTS[$c['type']] ?? 2;
            $c['score'] = ($weight / (1 + $c['distance'])) * (0.75 + mt_rand(0, 1000) / 2000);
        }
        unset($c);

        usort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        // Réordonnancement glouton pour la variété.
        $ordered = [];
        while ($candidates !== []) {
            $prev = end($ordered) ?: null;
            $prev2 = count($ordered) > 1 ? $ordered[count($ordered) - 2] : null;

            $pickedIndex = 0;
            foreach ($candidates as $i => $c) {
                $samePerson = $prev && $prev['subject_id'] === $c['subject_id'];
                $sameTypeRun = $prev && $prev2
                    && $prev['type'] === $c['type'] && $prev2['type'] === $c['type'];
                if (! $samePerson && ! $sameTypeRun) {
                    $pickedIndex = $i;
                    break;
                }
            }

            $picked = array_splice($candidates, $pickedIndex, 1)[0];
            $ordered[] = ['type' => $picked['type'], 'subject_id' => $picked['subject_id'], 'distance' => $picked['distance']];
        }

        return $ordered;
    }

    /** Hydrate la question pour le front (libellé, sujet, données d'UI). */
    private function present(User $user, QuestType $type, Model $subject): array
    {
        $question = [
            'type' => $type->value,
            'subject_id' => $subject->id,
            'key' => $type->key($subject->id),
            'allows_no' => $type->allowsNo(),
        ];

        if ($subject instanceof Person) {
            $question['prompt'] = $this->personPrompt($type, $subject);
            $question['person'] = $this->personPayload($subject);
        } elseif ($subject instanceof Media) {
            $question['prompt'] = $type === QuestType::MediaDate
                ? 'Quand cette photo a-t-elle été prise ?'
                : 'Où cette photo a-t-elle été prise ?';
            $question['media'] = [
                'id' => $subject->id,
                'image_url' => url("/vision/media/{$subject->id}/image?conversion=medium"),
                'title' => $subject->title,
            ];
        } elseif ($subject instanceof DetectedFace) {
            $question['prompt'] = 'Qui est cette personne ?';
            $question['face'] = [
                'id' => $subject->id,
                'crop_url' => url("/vision/faces/{$subject->id}/crop"),
                'media_id' => $subject->media_id,
                'suggestions' => array_slice($this->faceMatcher->rankedCandidates(
                    $subject,
                    FaceMatcher::MATCH_THRESHOLD,
                    $subject->media->user_id,
                ), 0, 4),
            ];
        }

        return $question;
    }

    private function personPrompt(QuestType $type, Person $person): string
    {
        $il = match ($person->gender) {
            'F' => 'elle',
            default => 'il',
        };
        $e = match ($person->gender) {
            'F' => 'e',
            'M' => '',
            default => '·e',
        };
        $name = $person->name;

        return match ($type) {
            QuestType::BirthDate => "Quelle est la date de naissance de {$name} ?",
            QuestType::BirthPlace => "Où est né{$e} {$name} ?",
            QuestType::DeathStatus => "{$name} est-{$il} encore en vie ?",
            QuestType::DeathDateOld => "Savez-vous quand {$name} est décédé{$e} ?",
            QuestType::DeathPlace => "Où {$name} est-{$il} décédé{$e} ?",
            QuestType::Gender => "{$name} était-{$il} un homme ou une femme ?",
            QuestType::ParentFather => "Qui est le père de {$name} ?",
            QuestType::ParentMother => "Qui est la mère de {$name} ?",
            QuestType::MaritalStatus => "{$name} a-t-{$il} été marié{$e} ou en couple ?",
            QuestType::MaidenName => "Quel était le nom de jeune fille de {$name} ?",
            QuestType::Job => "Quel métier {$name} a-t-{$il} exercé ?",
            QuestType::Education => "Où {$name} a-t-{$il} étudié (école, diplôme…) ?",
            QuestType::Residence => "Où {$name} a-t-{$il} habité ?",
            default => $name,
        };
    }

    private function personPayload(Person $person): array
    {
        $person->loadMissing('avatar');
        $person->loadCount(['detectedFaces as matched_faces_count' => function ($q) {
            $q->where('status', 'matched')->whereNotNull('bounding_box');
        }]);

        return [
            'id' => $person->id,
            'name' => $person->name,
            'birth_year' => $person->birth_date?->year,
            'avatar_url' => $this->avatarUrl($person),
        ];
    }

    /** Miroir de DashboardController::avatarUrl / PersonController::resolveAvatarUrl. */
    private function avatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            return route('people.avatarImage', $person);
        }

        if (($person->matched_faces_count ?? 0) > 0) {
            return url("/people/{$person->id}/face-avatar");
        }

        return null;
    }

    private function hasRelationship(string $personId): bool
    {
        return DB::table('person_relationships')
            ->where('person1_id', $personId)
            ->orWhere('person2_id', $personId)
            ->exists();
    }
}
