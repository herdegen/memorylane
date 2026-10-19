<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Garde-fous des identifications (visages et personnes sur une photo).
 *
 * - Bloquant : personne pas encore née, ou décédée, à la date de la photo
 *   (marge d'un an pour les dates approximatives des vieilles photos).
 * - Doutes (non bloquants, mais jamais d'association automatique) : bébé à
 *   la date de la photo (visages peu fiables), aucun lien de parenté proche
 *   avec les personnes déjà identifiées sur la photo (ou, à défaut, avec la
 *   fiche de celui qui l'a envoyée).
 */
class FacePlausibility
{
    public const DATE_TOLERANCE_YEARS = 1;

    public const BABY_MAX_AGE = 3;

    /**
     * Liens de parenté au-delà desquels on doute (parent 1, grand-parent 2,
     * oncle 3, cousin 4, cousin du conjoint 5, cousin issu de germain 6) :
     * les fêtes de famille réunissent cousins et belle-famille.
     */
    public const CLOSE_KIN_MAX_DISTANCE = 6;

    /** Écart (ans) entre l'âge estimé du visage et l'âge de la personne au-delà duquel on ne la suggère pas. */
    public const SUGGESTION_AGE_GAP = 30;

    /** Visages identifiés et datés nécessaires pour estimer une naissance inconnue. */
    public const MIN_AGE_SAMPLES = 5;

    /**
     * Écart (ans) entre un parent et son enfant pour borner une naissance
     * inconnue par l'arbre : 18 pour « né au plus tard » (écarter les ancêtres
     * d'une photo récente), 14 pour « né au plus tôt » (ne pas cacher l'enfant
     * d'un parent très jeune).
     */
    public const MIN_PARENT_GAP = 18;

    public const MIN_PARENT_GAP_EARLIEST = 14;

    /** Au-delà de cet âge (naissance la plus tardive possible d'après l'arbre), présumé disparu. */
    public const PRESUMED_MAX_AGE = 100;

    /** @var array<string, int>|null */
    private ?array $birthEstimates = null;

    /** @var array<string, array{latest: ?int, earliest: ?int}>|null */
    private ?array $genealogyBounds = null;

    /** Raison pour laquelle la personne ne peut pas figurer sur la photo, ou null. */
    public function impossibility(Person $person, Media $media): ?string
    {
        $takenAt = $media->taken_at;
        if (! $takenAt) {
            return null;
        }

        $tolerance = self::DATE_TOLERANCE_YEARS;
        if ($person->birth_date && $person->birth_date->gt($takenAt->copy()->addYears($tolerance))) {
            return sprintf('%s est %s en %d : %s ne peut pas figurer sur une photo de %d.',
                $person->name, $this->agree($person, 'né', 'née'), $person->birth_date->year,
                $this->agree($person, 'il', 'elle'), $takenAt->year);
        }
        if ($person->death_date && $person->death_date->lt($takenAt->copy()->subYears($tolerance))) {
            return sprintf('%s est %s en %d : %s ne peut pas figurer sur une photo de %d.',
                $person->name, $this->agree($person, 'décédé', 'décédée'), $person->death_date->year,
                $this->agree($person, 'il', 'elle'), $takenAt->year);
        }

        return null;
    }

    /** Lève une erreur de validation si l'identification est impossible. */
    public function assertPossible(Person $person, Media $media, string $field = 'person_id'): void
    {
        if ($reason = $this->impossibility($person, $media)) {
            throw ValidationException::withMessages([
                $field => $reason.' Si la date de la photo est fausse, corrigez-la d\'abord.',
            ]);
        }
    }

    /**
     * Doutes non bloquants sur une identification.
     *
     * @param  array<string,int>|null  $kin  distances de parenté (cf. kinDistances), calculées une fois par photo
     * @return list<array{code: string, label: string}>
     */
    /**
     * Suggestion invraisemblable (plus strict que impossibility, qui garde
     * une marge d'un an pour un choix fait à la main) : personne née après
     * la date de la photo, ou âge sans rapport avec celui du visage — d'après
     * sa date de naissance, sinon sa naissance estimée (âge apparent de ses
     * visages identifiés sur des photos datées).
     */
    public function unlikelySuggestion(Person $person, Media $media, ?int $faceAge): bool
    {
        $takenAt = $media->taken_at;
        if (! $takenAt) {
            return false;
        }
        if ($person->birth_date && $person->birth_date->gt($takenAt)) {
            return true;
        }
        if ($this->genealogyImplausible($person, $takenAt->year)) {
            return true;
        }
        $birthYear = $person->birth_date?->year ?? ($this->birthEstimates()[$person->id] ?? null);
        if ($faceAge === null || $birthYear === null) {
            return false;
        }

        return abs($faceAge - ($takenAt->year - $birthYear)) >= self::SUGGESTION_AGE_GAP;
    }

    /**
     * Année de naissance estimée des personnes (médiane de « année de la
     * photo − âge apparent » sur leurs visages identifiés datés), en cache
     * jusqu'au prochain changement d'identification.
     *
     * @return array<string, int>
     */
    public function birthEstimates(): array
    {
        if ($this->birthEstimates !== null) {
            return $this->birthEstimates;
        }
        $version = (int) Cache::get('face-refs-version', 0);

        return $this->birthEstimates = Cache::remember("face-birth-estimates:v{$version}", now()->addDay(), fn () => DB::table('detected_faces as f')
            ->join('media as m', 'm.id', '=', 'f.media_id')
            ->whereNotNull('f.person_id')->whereNotNull('f.age_estimate')->whereNotNull('m.taken_at')->whereNull('m.deleted_at')
            ->groupBy('f.person_id')
            ->havingRaw('count(*) >= ?', [self::MIN_AGE_SAMPLES])
            ->selectRaw('f.person_id, round(percentile_cont(0.5) within group (order by extract(year from m.taken_at) - f.age_estimate)) as est')
            ->pluck('est', 'person_id')
            ->map(fn ($year) => (int) $year)
            ->all());
    }

    /**
     * Naissance inconnue, mais l'arbre la borne : présumé disparu (né au plus
     * tard en X d'après ses descendants datés, donc plus de 100 ans à la date
     * de la photo — ex. un arrière-arrière-grand-parent sur une photo de 2020),
     * ou pas encore né (né au plus tôt en Y d'après ses ascendants datés).
     */
    public function genealogyImplausible(Person $person, int $year): bool
    {
        if ($person->birth_date || $person->death_date) {
            return false;   // dates connues : impossibility() s'en charge
        }
        $bounds = $this->genealogyBounds()[$person->id] ?? null;
        if (! $bounds) {
            return false;
        }

        return ($bounds['latest'] !== null && $year - $bounds['latest'] > self::PRESUMED_MAX_AGE)
            || ($bounds['earliest'] !== null && $bounds['earliest'] > $year + self::DATE_TOLERANCE_YEARS);
    }

    /**
     * Bornes de naissance des fiches SANS date, déduites de l'arbre : au plus
     * tard (année de naissance d'un descendant − 18 ans par génération), au
     * plus tôt (année d'un ascendant + 18 ans par génération). En cache tant
     * que l'arbre ne change pas.
     *
     * @return array<string, array{latest: ?int, earliest: ?int}>
     */
    public function genealogyBounds(): array
    {
        if ($this->genealogyBounds !== null) {
            return $this->genealogyBounds;
        }
        // Empreinte de l'arbre : y compris les liens parents écrits sans
        // updated_at (requêtes directes, imports).
        $stamp = DB::table('people')->selectRaw("count(*) as n, max(updated_at) as u, sum(hashtext(coalesce(father_id::text, '') || coalesce(mother_id::text, '') || coalesce(birth_date::text, ''))) as h")->first();
        $key = 'face-genealogy-bounds:'.md5(($stamp->n ?? 0).'|'.($stamp->u ?? '').'|'.($stamp->h ?? ''));

        return $this->genealogyBounds = Cache::remember($key, now()->addDay(), function () {
            $people = DB::table('people')->whereNull('deleted_at')
                ->get(['id', 'father_id', 'mother_id', 'birth_date']);
            $known = [];
            $parents = [];
            foreach ($people as $p) {
                if ($p->birth_date) {
                    $known[$p->id] = (int) substr((string) $p->birth_date, 0, 4);
                }
                $parents[$p->id] = array_values(array_filter([$p->father_id, $p->mother_id]));
            }

            // Au plus tard : remonte des enfants vers les parents (point fixe).
            $latest = $known;
            for ($round = 0, $changed = true; $changed && $round < 20; $round++) {
                $changed = false;
                foreach ($parents as $child => $ps) {
                    if (! isset($latest[$child])) {
                        continue;
                    }
                    foreach ($ps as $parent) {
                        if (isset($known[$parent])) {
                            continue;
                        }
                        $bound = $latest[$child] - self::MIN_PARENT_GAP;
                        if (! isset($latest[$parent]) || $bound < $latest[$parent]) {
                            $latest[$parent] = $bound;
                            $changed = true;
                        }
                    }
                }
            }

            // Au plus tôt : descend des parents vers les enfants.
            $earliest = $known;
            for ($round = 0, $changed = true; $changed && $round < 20; $round++) {
                $changed = false;
                foreach ($parents as $child => $ps) {
                    if (isset($known[$child])) {
                        continue;
                    }
                    foreach ($ps as $parent) {
                        if (! isset($earliest[$parent])) {
                            continue;
                        }
                        $bound = $earliest[$parent] + self::MIN_PARENT_GAP_EARLIEST;
                        if (! isset($earliest[$child]) || $bound > $earliest[$child]) {
                            $earliest[$child] = $bound;
                            $changed = true;
                        }
                    }
                }
            }

            $bounds = [];
            foreach ($parents as $id => $_) {
                if (isset($known[$id])) {
                    continue;
                }
                $l = $latest[$id] ?? null;
                $e = $earliest[$id] ?? null;
                if ($l !== null || $e !== null) {
                    $bounds[$id] = ['latest' => $l, 'earliest' => $e];
                }
            }

            return $bounds;
        });
    }

    public function doubts(Person $person, Media $media, ?array $kin = null): array
    {
        $doubts = [];

        // Choisi à la main malgré tout : on signale ce que dit l'arbre.
        if ($media->taken_at && $this->genealogyImplausible($person, $media->taken_at->year)) {
            $bounds = $this->genealogyBounds()[$person->id];
            $doubts[] = ['code' => 'generation', 'label' => $bounds['latest'] !== null && $media->taken_at->year - $bounds['latest'] > self::PRESUMED_MAX_AGE
                ? "D'après l'arbre, né".($person->gender === 'F' ? 'e' : '')." avant {$bounds['latest']}"
                : "D'après l'arbre, pas encore né".($person->gender === 'F' ? 'e' : '')];
        }

        if ($media->taken_at && $person->birth_date && $person->birth_date->lte($media->taken_at)) {
            $age = (int) $person->birth_date->diffInYears($media->taken_at);
            if ($age < self::BABY_MAX_AGE) {
                $doubts[] = ['code' => 'baby', 'label' => $age === 0 ? 'Bébé sur cette photo' : "{$age} an".($age > 1 ? 's' : '').' sur cette photo'];
            }
        }

        // La parenté ne dit rien d'un ami : ce doute ne vise que les fiches
        // du répertoire familial (un ami sur une photo de famille est normal).
        if ($kin !== null && $this->isFamily($person)
            && ($kin[$person->id] ?? PHP_INT_MAX) > self::CLOSE_KIN_MAX_DISTANCE) {
            $doubts[] = ['code' => 'distant', 'label' => isset($kin[$person->id])
                ? 'Parenté lointaine avec la photo'
                : 'Pas de lien familial à l\'époque de la photo'];
        }

        return $doubts;
    }

    /**
     * Fiche du répertoire familial ? Lue en base si la fiche a été chargée
     * sans cette colonne (listes allégées) — ne jamais supposer « famille ».
     */
    private function isFamily(Person $person): bool
    {
        if (array_key_exists('in_family_directory', $person->getAttributes())) {
            return (bool) $person->in_family_directory;
        }

        return (bool) (Person::whereKey($person->id)->value('in_family_directory') ?? true);
    }

    /**
     * Distance de parenté minimale de chaque fiche aux repères de la photo :
     * personnes déjà identifiées dessus (hors $exceptPersonId), sinon la fiche
     * de celui qui a envoyé la photo. Null quand il n'y a aucun repère.
     *
     * Parenté TELLE QU'ELLE EXISTAIT à la date de la photo : un mariage ne
     * relie les deux familles qu'à partir de sa date, et une personne pas
     * encore née ne peut servir de lien. Ainsi, sur une photo ancienne, la
     * belle-famille arrivée depuis n'est pas « de la famille ».
     *
     * @return array<string,int>|null
     */
    public function kinDistances(Media $media, ?string $exceptPersonId = null): ?array
    {
        $anchors = DB::table('media_person')->where('media_id', $media->id)->pluck('person_id')
            ->merge(DetectedFace::where('media_id', $media->id)->whereNotNull('person_id')->pluck('person_id'))
            ->filter(fn ($id) => $id && $id !== $exceptPersonId)
            ->unique()->values();
        // Repères familiaux seulement : un ami identifié sur la photo ne
        // relie personne à l'arbre.
        $anchors = Person::whereIn('id', $anchors)->where('in_family_directory', true)->pluck('id');

        if ($anchors->isEmpty() && ($ownerPerson = $media->user?->person_id)) {
            $anchors = collect([$ownerPerson]);
        }
        if ($anchors->isEmpty()) {
            return null;
        }

        $adj = $this->graphAt($media->taken_at?->copy()->addYears(self::DATE_TOLERANCE_YEARS));

        // Parcours en largeur depuis tous les repères à la fois.
        $distance = [];
        $queue = [];
        foreach ($anchors as $anchor) {
            if (isset($adj[$anchor])) {
                $distance[$anchor] = 0;
                $queue[] = $anchor;
            }
        }
        for ($k = 0; $k < count($queue); $k++) {
            $current = $queue[$k];
            foreach ($adj[$current] as $next) {
                if (! isset($distance[$next])) {
                    $distance[$next] = $distance[$current] + 1;
                    $queue[] = $next;
                }
            }
        }

        return $distance;
    }

    /** @var array<string, array<string, list<string>>> graphe par date (mémo de requête) */
    private array $graphs = [];

    /**
     * Graphe de parenté à une date : personnes nées (date inconnue : gardée),
     * liens parent/enfant, unions commencées (date inconnue : gardée).
     *
     * @return array<string, list<string>>
     */
    private function graphAt(?\Carbon\CarbonInterface $at): array
    {
        $key = $at?->format('Y-m-d') ?? 'toujours';
        if (isset($this->graphs[$key])) {
            return $this->graphs[$key];
        }

        $people = DB::table('people')->whereNull('deleted_at')
            ->when($at, fn ($q) => $q->where(fn ($w) => $w->whereNull('birth_date')->orWhere('birth_date', '<=', $at)))
            ->get(['id', 'father_id', 'mother_id']);
        $adj = array_fill_keys($people->pluck('id')->all(), []);

        foreach ($people as $p) {
            foreach ([$p->father_id, $p->mother_id] as $parent) {
                if ($parent && isset($adj[$parent])) {
                    $adj[$p->id][] = $parent;
                    $adj[$parent][] = $p->id;
                }
            }
        }
        $unions = DB::table('person_relationships')
            ->when($at, fn ($q) => $q->where(fn ($w) => $w->whereNull('start_date')->orWhere('start_date', '<=', $at)))
            ->get(['person1_id', 'person2_id']);
        foreach ($unions as $u) {
            if (isset($adj[$u->person1_id], $adj[$u->person2_id])) {
                $adj[$u->person1_id][] = $u->person2_id;
                $adj[$u->person2_id][] = $u->person1_id;
            }
        }

        return $this->graphs[$key] = $adj;
    }

    private function agree(Person $person, string $masculine, string $feminine): string
    {
        return match ($person->gender) {
            'F' => $feminine,
            'M' => $masculine,
            default => $masculine === 'il' ? 'il ou elle' : $masculine.'(e)',
        };
    }
}
