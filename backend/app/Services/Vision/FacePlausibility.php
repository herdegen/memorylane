<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
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
    public function doubts(Person $person, Media $media, ?array $kin = null): array
    {
        $doubts = [];

        if ($media->taken_at && $person->birth_date && $person->birth_date->lte($media->taken_at)) {
            $age = (int) $person->birth_date->diffInYears($media->taken_at);
            if ($age < self::BABY_MAX_AGE) {
                $doubts[] = ['code' => 'baby', 'label' => $age === 0 ? 'Bébé sur cette photo' : "{$age} an".($age > 1 ? 's' : '').' sur cette photo'];
            }
        }

        // La parenté ne dit rien d'un ami : ce doute ne vise que les fiches
        // du répertoire familial (un ami sur une photo de famille est normal).
        if ($kin !== null && $person->in_family_directory !== false
            && ($kin[$person->id] ?? PHP_INT_MAX) > self::CLOSE_KIN_MAX_DISTANCE) {
            $doubts[] = ['code' => 'distant', 'label' => isset($kin[$person->id])
                ? 'Parenté lointaine avec la photo'
                : 'Pas de lien familial à l\'époque de la photo'];
        }

        return $doubts;
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
