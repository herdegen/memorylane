<?php

namespace App\Services\Newsletter;

use App\Enums\QuestType;
use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonRelationship;
use App\Models\QuestAnswer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Encart « Yves a indiqué que tu es né à Lyon le 3 mars 1958 : c'est bien
 * ça ? » de la lettre famille : ce que D'AUTRES ont saisi sur la fiche du
 * destinataire, pour qu'il le vérifie (et le corrige lui-même au besoin).
 *
 * Faits possibles : naissance (date, lieu), nom de naissance, parents,
 * unions (date, lieu, ou année donnée en réponse à une quête), métiers,
 * études et lieux de vie de la frise. Les réponses aux quêtes s'appliquent
 * à la fiche au nom de celui qui répond (historique, moments, unions) : un
 * fait venu d'une quête est donc attribué à son auteur comme les autres.
 *
 * Jamais ce que le destinataire a saisi lui-même, jamais une saisie annulée
 * (historique person_changes, annulations comprises). Dans le doute — valeur
 * modifiée hors historique, union ou moment retouchés après coup par on ne
 * sait qui — le fait est écarté. Avant l'historique (11 octobre 2026), un
 * champ jamais modifié depuis est attribué au créateur de la fiche (imports
 * GEDCOM compris).
 *
 * L'auteur n'est nommé que s'il partage un cercle familial avec le
 * destinataire (sinon « On a indiqué que… ») ; conjoint et parents ne
 * sont cités que si leur fiche est visible du destinataire.
 */
class OwnFactsFinder
{
    private const TIMELINE_TYPES = ['job', 'education', 'residence'];

    /** Union ou moment retouchés au-delà de ce délai après leur ajout : auteur incertain. */
    private const EDIT_GRACE_SECONDS = 120;

    /** Une annulation et la ligne d'historique qu'elle crée : quelques secondes d'écart au plus. */
    private const REVERT_WINDOW_SECONDS = 5;

    private User $user;

    private Person $me;

    /** @var Collection<int, PersonChange> historique de sa fiche, du plus récent au plus ancien */
    private Collection $changes;

    /** @var list<string> */
    private array $familyCircleIds = [];

    /** @var array<string, ?string> prénom affichable de chaque auteur */
    private array $authorNames = [];

    /**
     * Les faits de sa fiche saisis par d'autres, du plus récemment saisi au
     * plus ancien, rédigés dans la langue courante.
     *
     * @return list<array{key: string, text: string, url: string, at: CarbonInterface}>
     */
    public function for(User $user): array
    {
        $me = $user->person_id ? Person::find($user->person_id) : null;
        if (! $me) {
            return [];
        }

        $this->user = $user;
        $this->me = $me;
        $this->changes = PersonChange::where('person_id', $me->id)->orderByDesc('created_at')->get();
        $this->familyCircleIds = $user->circles()->where('kind', Household::KIND_FAMILY)->pluck('id')->all();
        $this->authorNames = [];

        $facts = [...$this->birth(), ...$this->maidenName(), ...$this->parents(), ...$this->unions(), ...$this->timeline()];
        usort($facts, fn ($a, $b) => $b['at'] <=> $a['at']);

        return $facts;
    }

    // ── Les faits ──────────────────────────────────────────────────────

    /** Date et lieu de naissance : un seul fait s'ils viennent de la même personne. */
    private function birth(): array
    {
        $date = $this->byOther($this->fieldAuthor('birth_date'));
        $place = $this->byOther($this->fieldAuthor('birth_place'));

        $groups = $date && $place && $date['user_id'] === $place['user_id']
            ? [[$date, true, true]]
            : array_filter([$date ? [$date, true, false] : null, $place ? [$place, false, true] : null]);

        $facts = [];
        foreach ($groups as [$author, $withDate, $withPlace]) {
            $values = ['details' => $this->details(
                place: $withPlace ? $this->me->birth_place : null,
                date: $withDate ? $this->me->birth_date : null,
            )];
            $clause = match ($this->me->gender) {
                'F' => __('tu es née :details', $values),
                'M' => __('tu es né :details', $values),
                default => __('tu es né(e) :details', $values),
            };
            $payload = ($withDate ? $this->me->birth_date->toDateString() : '').'|'.($withPlace ? $this->me->birth_place : '');
            $facts[] = $this->fact('birth', $payload, $author, $clause);
        }

        return $facts;
    }

    private function maidenName(): array
    {
        $author = $this->byOther($this->fieldAuthor('maiden_name'));

        return $author ? [$this->fact('maiden_name', $this->me->maiden_name, $author,
            __('ton nom de naissance est :name', ['name' => $this->me->maiden_name]))] : [];
    }

    /** Père et mère : un seul fait s'ils ont été indiqués par la même personne. */
    private function parents(): array
    {
        $father = $this->parent('father_id');
        $mother = $this->parent('mother_id');

        if ($father && $mother && $father[1]['user_id'] === $mother[1]['user_id']) {
            return [$this->fact('parents', $father[0]->id.'|'.$mother[0]->id, $father[1],
                __('tes parents sont :father et :mother', ['father' => $father[0]->name, 'mother' => $mother[0]->name]))];
        }

        return array_values(array_filter([
            $father ? $this->fact('father', $father[0]->id, $father[1], __('ton père est :father', ['father' => $father[0]->name])) : null,
            $mother ? $this->fact('mother', $mother[0]->id, $mother[1], __('ta mère est :mother', ['mother' => $mother[0]->name])) : null,
        ]));
    }

    /** @return array{0: Person, 1: array}|null le parent (visible) et qui l'a indiqué */
    private function parent(string $field): ?array
    {
        $author = $this->byOther($this->fieldAuthor($field));
        $parent = $author ? Person::visibleTo($this->user)->find($this->me->{$field}) : null;

        return $parent ? [$parent, $author] : null;
    }

    /** Unions avec une date, un lieu, ou l'année donnée en réponse à une quête. */
    private function unions(): array
    {
        $facts = [];
        $unions = PersonRelationship::where(fn ($q) => $q->where('person1_id', $this->me->id)->orWhere('person2_id', $this->me->id))->get();

        foreach ($unions as $union) {
            $otherId = $union->person1_id === $this->me->id ? $union->person2_id : $union->person1_id;
            $spouse = Person::visibleTo($this->user)->find($otherId);
            if (! $spouse) {
                continue;
            }
            // Dates retouchées après l'ajout (back-office) : on ne sait plus par qui.
            if ($union->created_at && $union->updated_at
                && abs($union->updated_at->diffInSeconds($union->created_at)) > self::EDIT_GRACE_SECONDS) {
                continue;
            }

            $author = $this->unionAuthor($union, $spouse);
            $year = null;
            if (! $union->start_date) {
                // « Marié en 1985 » : l'année d'une réponse de quête ne remplit
                // pas start_date, elle reste dans le journal.
                $answer = $this->maritalAnswer($otherId);
                if ($answer) {
                    $year = (int) $answer->payload['year'];
                    $author = ['user_id' => $answer->user_id, 'at' => $answer->created_at];
                }
            }
            if (! $this->byOther($author) || (! $union->start_date && ! $union->start_place && ! $year)) {
                continue;
            }

            $values = [
                'spouse' => $spouse->name,
                'details' => $this->details(place: $union->start_place, date: $union->start_date, year: $year),
            ];
            $clause = match (true) {
                $union->type === 'partner' => __('ta vie avec :spouse a commencé :details', $values),
                $this->me->gender === 'F' => __('tu t’es mariée avec :spouse :details', $values),
                $this->me->gender === 'M' => __('tu t’es marié avec :spouse :details', $values),
                default => __('tu t’es marié(e) avec :spouse :details', $values),
            };
            $payload = implode('|', [$union->id, $union->start_date?->toDateString(), $union->start_place, $year]);
            $facts[] = $this->fact('union', $payload, $author, $clause, '#frise');
        }

        return $facts;
    }

    /**
     * Qui a ajouté l'union : la ligne « conjoint ajouté » la plus récente de
     * l'historique (annulée : l'union réapparue hors historique, incertain) ;
     * sans historique, le créateur commun des deux fiches (import GEDCOM).
     */
    private function unionAuthor(PersonRelationship $union, Person $spouse): ?array
    {
        $added = $this->changes->where('action', 'spouse_added')
            ->first(fn ($c) => ($c->diff['spouse_id'] ?? null) === $spouse->id);

        if ($added) {
            return $added->reverted_at ? null : ['user_id' => $added->user_id, 'at' => $added->created_at];
        }

        return $this->me->user_id === $spouse->user_id
            ? ['user_id' => $this->me->user_id, 'at' => $union->created_at ?? $this->me->created_at]
            : null;
    }

    /** Réponse de quête « marié(e) avec … en telle année » sur l'un ou l'autre des deux. */
    private function maritalAnswer(string $spouseId): ?QuestAnswer
    {
        return QuestAnswer::where('question_type', QuestType::MaritalStatus->value)
            ->where('answer_kind', 'answered')
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('subject_id', $this->me->id)->whereRaw("payload->>'spouse_id' = ?", [$spouseId]))
                ->orWhere(fn ($w) => $w->where('subject_id', $spouseId)->whereRaw("payload->>'spouse_id' = ?", [$this->me->id])))
            ->whereRaw("coalesce(payload->>'year', '') <> ''")
            ->latest()
            ->first();
    }

    /** Métiers, études et lieux de vie ajoutés à sa frise par d'autres (et non retouchés depuis). */
    private function timeline(): array
    {
        return LifeEvent::where('person_id', $this->me->id)
            ->whereIn('type', self::TIMELINE_TYPES)
            ->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $this->user->id))
            ->whereNotNull('event_date')
            ->get()
            ->reject(fn ($e) => $e->created_at && $e->updated_at
                && abs($e->updated_at->diffInSeconds($e->created_at)) > self::EDIT_GRACE_SECONDS)
            ->map(function (LifeEvent $event) {
                $values = [
                    'title' => $event->title,
                    'kind' => match ($event->type) {
                        'job' => __('métier'),
                        'education' => __('études'),
                        default => __('lieu de vie'),
                    },
                    'year' => $event->event_date->year,
                ];
                $who = $this->authorName($event->user_id);

                return [
                    'key' => 'event:'.substr(md5($event->id.'|'.$event->title.'|'.$event->event_date->toDateString()), 0, 12),
                    'text' => $who
                        ? __(':name a ajouté à ta frise de vie « :title » (:kind, :year) : c’est bien ça ?', ['name' => $who] + $values)
                        : __('On a ajouté à ta frise de vie « :title » (:kind, :year) : c’est bien ça ?', $values),
                    'url' => url("/people/{$this->me->id}").'#frise',
                    'at' => $event->created_at ?? $this->me->created_at,
                ];
            })
            ->values()->all();
    }

    // ── Qui a saisi quoi ───────────────────────────────────────────────

    /**
     * Auteur de la valeur actuelle d'un champ de sa fiche : la dernière
     * modification de ce champ dans l'historique (les annulations sont
     * sautées : l'auteur d'une valeur rétablie est celui qui l'avait saisie) ;
     * jamais modifié depuis l'historique → la création de la fiche. Null si
     * le champ est vide ou si l'historique ne colle pas à la valeur actuelle.
     *
     * @return array{user_id: ?string, at: CarbonInterface}|null
     */
    private function fieldAuthor(string $field): ?array
    {
        $current = $this->normalize($field, $this->me->getRawOriginal($field));
        if ($current === null) {
            return null;
        }

        $live = $this->changes->whereNull('reverted_at');
        foreach ($live->where('action', 'updated') as $change) {
            if (! array_key_exists($field, $change->diff ?? [])) {
                continue;
            }
            if ($this->undoesReverted($change, $field)) {
                continue;
            }
            [, $new] = $change->diff[$field] + [null, null];

            return $this->normalize($field, $new) === $current
                ? ['user_id' => $change->user_id, 'at' => $change->created_at]
                : null;
        }

        $created = $live->firstWhere('action', 'created');

        return $created
            ? ['user_id' => $created->user_id, 'at' => $created->created_at]
            : ['user_id' => $this->me->user_id, 'at' => $this->me->created_at];
    }

    /** Cette ligne est-elle l'annulation (back-office) d'une autre, pour ce champ ? */
    private function undoesReverted(PersonChange $change, string $field): bool
    {
        [$old, $new] = $change->diff[$field] + [null, null];

        return $this->changes->contains(fn ($r) => $r->reverted_at !== null
            && $r->reverted_by === $change->user_id
            && abs($r->reverted_at->diffInSeconds($change->created_at)) <= self::REVERT_WINDOW_SECONDS
            && array_key_exists($field, $r->diff ?? [])
            && $this->normalize($field, $r->diff[$field][0] ?? null) === $this->normalize($field, $new)
            && $this->normalize($field, $r->diff[$field][1] ?? null) === $this->normalize($field, $old));
    }

    /** Même normalisation que PersonObserver (vide → null, dates sur 10 caractères). */
    private function normalize(string $field, mixed $value): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if (in_array($field, ['birth_date', 'death_date'], true)) {
            return substr((string) $value, 0, 10);
        }

        return is_scalar($value) ? (string) $value : json_encode($value);
    }

    /** Garde un auteur qui n'est pas le destinataire (auteur inconnu compris). */
    private function byOther(?array $author): ?array
    {
        return $author !== null && $author['user_id'] !== $this->user->id ? $author : null;
    }

    /** Prénom de l'auteur s'il partage un cercle familial avec le destinataire. */
    private function authorName(?string $userId): ?string
    {
        if ($userId === null || $this->familyCircleIds === []) {
            return null;
        }

        return $this->authorNames[$userId] ??= (function () use ($userId) {
            $shares = DB::table('household_user')->where('user_id', $userId)
                ->whereIn('household_id', $this->familyCircleIds)->exists();
            $name = $shares ? User::whereKey($userId)->value('name') : null;

            return $name ? (strtok(trim($name), ' ') ?: $name) : null;
        })();
    }

    // ── Rédaction ──────────────────────────────────────────────────────

    private function fact(string $kind, string $payload, array $author, string $clause, string $anchor = ''): array
    {
        $who = $this->authorName($author['user_id']);

        return [
            'key' => $kind.':'.substr(md5($payload), 0, 12),
            'text' => $who
                ? __(':name a indiqué que :fact : c’est bien ça ?', ['name' => $who, 'fact' => $clause])
                : __('On a indiqué que :fact : c’est bien ça ?', ['fact' => $clause]),
            'url' => url("/people/{$this->me->id}").$anchor,
            'at' => $author['at'] ?? $this->me->created_at,
        ];
    }

    /** « à Lyon le 3 mars 1958 », « à Lyon », « en 1985 »… */
    private function details(?string $place = null, ?CarbonInterface $date = null, ?int $year = null): string
    {
        $where = $place ? trim((string) strtok($place, ',')) : '';

        return implode(' ', array_filter([
            $where !== '' ? __('à :place', ['place' => $where]) : null,
            match (true) {
                $date !== null => __('le :date', ['date' => $this->longDate($date)]),
                $year !== null => __('en :year', ['year' => $year]),
                default => null,
            },
        ]));
    }

    /** « 3 mars 1958 » (« 1er mars »), « 3 March 1958 », « 3 de marzo de 1958 ». */
    private function longDate(CarbonInterface $date): string
    {
        $locale = app()->getLocale();
        $date = $date->copy()->locale($locale);

        return match (true) {
            $locale === 'es' => $date->translatedFormat('j \d\e F \d\e Y'),
            $locale === 'fr' && $date->day === 1 => $date->translatedFormat('j\e\r F Y'),
            default => $date->translatedFormat('j F Y'),
        };
    }
}
