<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Services\GenealogyService;
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

    /** Parent 1, grand-parent 2, oncle 3, cousin 4. */
    public const CLOSE_KIN_MAX_DISTANCE = 4;

    public function __construct(private GenealogyService $genealogy) {}

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

        if ($kin !== null && ($kin[$person->id] ?? PHP_INT_MAX) > self::CLOSE_KIN_MAX_DISTANCE) {
            $doubts[] = ['code' => 'distant', 'label' => 'Pas de parenté proche avec la photo'];
        }

        return $doubts;
    }

    /**
     * Distance de parenté minimale de chaque fiche aux repères de la photo :
     * personnes déjà identifiées dessus (hors $exceptPersonId), sinon la fiche
     * de celui qui a envoyé la photo. Null quand il n'y a aucun repère.
     *
     * @return array<string,int>|null
     */
    public function kinDistances(Media $media, ?string $exceptPersonId = null): ?array
    {
        $anchors = DB::table('media_person')->where('media_id', $media->id)->pluck('person_id')
            ->merge(DetectedFace::where('media_id', $media->id)->whereNotNull('person_id')->pluck('person_id'))
            ->filter(fn ($id) => $id && $id !== $exceptPersonId)
            ->unique()->values();

        if ($anchors->isEmpty() && ($ownerPerson = $media->user?->person_id)) {
            $anchors = collect([$ownerPerson]);
        }
        if ($anchors->isEmpty()) {
            return null;
        }

        $min = [];
        foreach ($anchors as $anchor) {
            foreach ($this->genealogy->proximity($anchor)['distance'] as $id => $d) {
                $min[$id] = min($min[$id] ?? PHP_INT_MAX, $d);
            }
        }

        return $min;
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
