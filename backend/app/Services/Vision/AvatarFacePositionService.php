<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use Illuminate\Support\Collection;

/**
 * Cadrage intelligent des avatars « photo entière » (issue #51).
 *
 * Quand l'avatar d'une personne est une photo complète (avatar_media_id) et
 * qu'elle y a un visage détecté/confirmé, on calcule le centre de la boîte du
 * visage (bounding_box en % de l'image) pour produire un `object-position`
 * CSS ("42% 18%") : les cartes portrait affichées en `object-cover` cadrent
 * alors sur le visage au lieu du centre de l'image.
 *
 * Approximation assumée : object-position en % du point d'intérêt, pas la
 * projection exacte (qui dépend du ratio conteneur/image, variable). L'endpoint
 * avatarImage sert la conversion `small` (ratio conservé) donc les % du bbox
 * restent valides ; le fallback rare `thumbnail` (carré recadré) rend la
 * position légèrement fausse mais sans visage coupé.
 */
class AvatarFacePositionService
{
    /**
     * Positions par lot (1 seule requête) pour des personnes hydratées.
     *
     * @param  Collection<int, \App\Models\Person>  $people
     * @return array<string, string> person_id => "x% y%"
     */
    public function forPeople(Collection $people): array
    {
        // Seules les personnes à avatar explicite sont concernées : le
        // fallback face-avatar est déjà un recadrage centré sur le visage.
        $avatarByPerson = $people
            ->filter(fn ($p) => $p && $p->avatar_media_id)
            ->mapWithKeys(fn ($p) => [$p->id => $p->avatar_media_id]);

        if ($avatarByPerson->isEmpty()) {
            return [];
        }

        $faces = DetectedFace::query()
            ->whereIn('person_id', $avatarByPerson->keys())
            ->whereIn('media_id', $avatarByPerson->values())
            ->where('status', 'matched')
            ->whereNotNull('bounding_box')
            ->orderByDesc('confidence')
            ->get(['person_id', 'media_id', 'bounding_box']);

        $positions = [];

        foreach ($faces as $face) {
            // Le whereIn croise les listes : ne garder que le visage de la
            // personne SUR SA photo d'avatar, le plus confiant d'abord.
            if (($avatarByPerson[$face->person_id] ?? null) !== $face->media_id
                || isset($positions[$face->person_id])) {
                continue;
            }

            if ($position = $this->objectPosition($face->bounding_box)) {
                $positions[$face->person_id] = $position;
            }
        }

        return $positions;
    }

    /** Centre du visage → valeur CSS object-position, ou null si boîte invalide. */
    private function objectPosition(array $box): ?string
    {
        if (! isset($box['x'], $box['y'], $box['width'], $box['height'])) {
            return null;
        }

        $cx = min(100, max(0, $box['x'] + $box['width'] / 2));
        $cy = min(100, max(0, $box['y'] + $box['height'] / 2));

        return round($cx).'% '.round($cy).'%';
    }
}
