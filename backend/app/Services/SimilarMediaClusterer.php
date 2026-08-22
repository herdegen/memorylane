<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\DB;

/**
 * Clustering des quasi-doublons (issue #42, tranche 3).
 *
 * Regroupe les photos visuellement proches d'un MÊME propriétaire : deux
 * photos sont liées si la distance de Hamming entre leurs dHash
 * (perceptual_hash, 64 bits) est ≤ au seuil, puis les groupes sont fermés par
 * transitivité (union-find) — une rafale A~B~C forme un seul groupe même si
 * A et C sont plus éloignées.
 *
 * Les paires de doublons binaires EXACTS (même content_hash) ne créent pas de
 * lien : elles relèvent de l'écran « Doublons » (tranche 1). Elles peuvent en
 * revanche être entraînées dans un groupe via une troisième photo proche.
 *
 * Le résultat est matérialisé dans media.similar_group_id (plus petit UUID du
 * groupe, donc stable à composition constante) : l'écran Filament se contente
 * ensuite d'un GROUP BY, comme pour les doublons exacts.
 *
 * Comparaison en O(n²) par propriétaire : ~700 photos → ~250k distances,
 * quelques dizaines de ms. Si la photothèque atteint les dizaines de milliers,
 * passer à un index BK-tree ou à un pré-tri par préfixe de hash.
 */
class SimilarMediaClusterer
{
    /** Seuil par défaut (prudent) : ≤ 8 bits différents sur 64. */
    public const DEFAULT_THRESHOLD = 8;

    /**
     * (Re)calcule tous les groupes de quasi-doublons.
     *
     * @return array{photos: int, groups: int, grouped: int}
     */
    public function cluster(int $threshold = self::DEFAULT_THRESHOLD): array
    {
        $photos = Media::query()
            ->where('type', 'photo')
            ->whereNotNull('perceptual_hash')
            ->get(['id', 'user_id', 'perceptual_hash', 'content_hash']);

        $parent = [];
        $find = function (string $id) use (&$parent, &$find): string {
            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };

        foreach ($photos as $photo) {
            $parent[$photo->id] = $photo->id;
        }

        // Liens deux à deux, par propriétaire.
        foreach ($photos->groupBy('user_id') as $owned) {
            $items = $owned->values();
            $count = $items->count();

            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    $a = $items[$i];
                    $b = $items[$j];

                    // Doublon binaire exact → écran « Doublons », pas de lien ici.
                    if ($a->content_hash !== null && $a->content_hash === $b->content_hash) {
                        continue;
                    }

                    if (PerceptualHashService::hammingDistance($a->perceptual_hash, $b->perceptual_hash) <= $threshold) {
                        $parent[$find($a->id)] = $find($b->id);
                    }
                }
            }
        }

        // Groupes (≥ 2 membres), identifiés par leur plus petit id.
        $clusters = [];
        foreach ($photos as $photo) {
            $clusters[$find($photo->id)][] = $photo->id;
        }

        $grouped = 0;
        $groups = 0;

        DB::transaction(function () use ($clusters, &$grouped, &$groups) {
            // Remise à zéro (corbeille comprise) : les groupes dissous (photos
            // supprimées, seuil abaissé…) doivent disparaître.
            Media::withTrashed()->whereNotNull('similar_group_id')->update(['similar_group_id' => null]);

            foreach ($clusters as $ids) {
                if (count($ids) < 2) {
                    continue;
                }

                sort($ids);
                Media::whereIn('id', $ids)->update(['similar_group_id' => $ids[0]]);
                $groups++;
                $grouped += count($ids);
            }
        });

        return ['photos' => $photos->count(), 'groups' => $groups, 'grouped' => $grouped];
    }
}
