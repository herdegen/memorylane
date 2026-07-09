<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;

/**
 * Reconnaissance des personnes à partir des descripteurs faciaux (face-api.js,
 * 128 floats, distance euclidienne native).
 *
 * Logique serveur PURE, scopée par PROPRIÉTAIRE du média : elle ne dépend pas
 * de l'utilisateur authentifié. C'est indispensable pour la relancer depuis
 * l'admin (Filament), où l'acteur n'est pas le propriétaire des médias.
 */
class FaceMatcher
{
    /**
     * Distance euclidienne maximale entre deux descripteurs pour considérer
     * qu'il s'agit de la même personne (suggestion).
     */
    public const MATCH_THRESHOLD = 0.6;

    /**
     * Seuil (plus strict) au-dessous duquel on associe AUTOMATIQUEMENT la
     * personne, sans confirmation (auto-association en tâche de fond).
     */
    public const AUTO_MATCH_THRESHOLD = 0.45;

    /**
     * Auto-association d'un visage : associe le plus proche voisin labellisé
     * (parmi les visages du même propriétaire) UNIQUEMENT si la distance est
     * sous le seuil strict. Retourne l'entrée candidate retenue, ou null.
     *
     * @return array{person: array{id: string, name: string}, distance: float, score: float}|null
     */
    public function autoMatch(DetectedFace $face, string $ownerId): ?array
    {
        // Ne jamais écraser une association existante.
        if ($face->person_id || $face->status !== 'unmatched') {
            return null;
        }

        $best = $this->rankedCandidates($face, self::AUTO_MATCH_THRESHOLD, $ownerId)[0] ?? null;

        if (! $best) {
            return null;
        }

        $this->applyMatch($face, $best['person']['id']);

        return $best;
    }

    /**
     * Personnes candidates (plus proche voisin par personne) sous $maxDistance,
     * triées par distance croissante. Chaque entrée :
     * {person{id,name}, distance, score}. Les candidats sont restreints aux
     * visages labellisés appartenant au propriétaire $ownerId.
     *
     * @return array<int, array{person: array{id: string, name: string}, distance: float, score: float}>
     */
    public function rankedCandidates(DetectedFace $face, float $maxDistance, string $ownerId): array
    {
        $target = $face->embedding;

        if (! is_array($target) || count($target) !== 128) {
            return [];
        }

        $labelled = DetectedFace::query()
            ->whereNotNull('person_id')
            ->whereNotNull('embedding')
            ->where('id', '!=', $face->id)
            ->whereHas('media', fn ($q) => $q->where('user_id', $ownerId))
            ->with('person:id,name')
            ->get();

        $bestByPerson = [];

        foreach ($labelled as $candidate) {
            if (! $candidate->person) {
                continue;
            }

            $distance = $this->euclideanDistance($target, $candidate->embedding);

            if ($distance > $maxDistance) {
                continue;
            }

            $personId = $candidate->person->id;

            if (! isset($bestByPerson[$personId]) || $distance < $bestByPerson[$personId]['distance']) {
                $bestByPerson[$personId] = [
                    'person' => [
                        'id' => $candidate->person->id,
                        'name' => $candidate->person->name,
                    ],
                    'distance' => round($distance, 4),
                    // Score de similarité (0..1) : 1 = identique, 0 = au seuil de suggestion.
                    'score' => round(max(0, 1 - $distance / self::MATCH_THRESHOLD), 4),
                ];
            }
        }

        $candidates = array_values($bestByPerson);
        usort($candidates, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return $candidates;
    }

    /**
     * Associe un visage à une personne (statut matched + pivot media_person).
     */
    public function applyMatch(DetectedFace $face, string $personId): void
    {
        $face->update([
            'person_id' => $personId,
            'status' => 'matched',
        ]);

        $face->media->people()->syncWithoutDetaching([
            $personId => [
                'face_coordinates' => json_encode($face->bounding_box),
            ],
        ]);
    }

    public function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $v) {
            $d = $v - ($b[$i] ?? 0);
            $sum += $d * $d;
        }

        return sqrt($sum);
    }
}
