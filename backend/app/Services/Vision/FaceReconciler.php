<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use App\Models\Media;
use Illuminate\Support\Facades\DB;

/**
 * Intègre une analyse InsightFace à un média SANS RIEN PERDRE :
 *
 * - visage existant retrouvé (même zone) → on lui ajoute l'empreinte
 *   InsightFace ; sa boîte, son statut et son identification ne bougent pas ;
 * - visage existant non retrouvé (de profil, dessiné à la main…) → conservé
 *   tel quel, avec une empreinte calculée sur sa zone ;
 * - visage nouveau (manqué par face-api) → ajouté, « à identifier ».
 *
 * Aucune suppression, aucune réattribution : rejouable sans risque (une
 * deuxième passe retrouve les visages ajoutés par la première).
 */
class FaceReconciler
{
    /** En dessous, le visage est trop petit pour être reconnu (pixels). */
    public const MIN_FACE_PX = 20;

    public function __construct(private FaceEngine $engine, private FaceMatcher $matcher) {}

    /**
     * @param  list<array>  $detections  sortie de FaceEngine::analyze
     * @return array{updated: int, kept: int, added: int, skipped: int}
     */
    public function apply(Media $media, array $detections): array
    {
        $detections = array_values(array_filter($detections, fn ($d) => $d['px'] >= self::MIN_FACE_PX));
        $existing = $media->detectedFaces()->get();
        $pairs = $this->pair($existing->all(), $detections);

        $stats = ['updated' => 0, 'kept' => 0, 'added' => 0, 'skipped' => 0];
        $added = [];

        DB::transaction(function () use ($media, $existing, $detections, $pairs, &$stats, &$added) {
            foreach ($existing as $i => $face) {
                if (isset($pairs[$i])) {
                    $d = $detections[$pairs[$i]];
                    $face->forceFill(['embedding_v2' => $d['embedding'], 'age_estimate' => $d['age'], 'engine' => 'insightface'])->save();
                    $stats['updated']++;
                } else {
                    $stats['kept']++;
                }
            }

            $matched = array_flip($pairs);
            foreach ($detections as $j => $d) {
                if (isset($matched[$j])) {
                    continue;
                }
                $added[] = $media->detectedFaces()->create([
                    'bounding_box' => $d['box'],
                    'confidence' => $d['score'],
                    'provider' => 'insightface',
                    'status' => 'unmatched',
                    'embedding_v2' => $d['embedding'],
                    'age_estimate' => $d['age'],
                    'engine' => 'insightface',
                ]);
                $stats['added']++;
            }

            $media->metadata()->updateOrCreate([], [
                'vision_status' => 'completed',
                'vision_provider' => 'insightface',
                'vision_faces_count' => $media->detectedFaces()->whereIn('status', ['unmatched', 'matched'])->count(),
                'vision_processed_at' => now(),
                'vision_error' => null,
            ]);
        });

        // Visages existants non retrouvés : empreinte calculée sur leur zone,
        // en un seul envoi (hors transaction : appel au service). Les visages
        // ignorés n'en ont pas besoin (jamais proposés).
        $missed = $existing->filter(fn ($face, $i) => ! isset($pairs[$i]) && $face->embedding_v2 === null
            && $face->status !== 'dismissed' && is_array($face->bounding_box))->values();
        foreach ($this->engine->embedBoxes($media, $missed->pluck('bounding_box')->all()) as $k => $embedded) {
            if ($embedded) {
                $missed[$k]->forceFill(['embedding_v2' => $embedded['embedding'], 'age_estimate' => $embedded['age'], 'engine' => 'insightface-box'])->save();
            } else {
                $stats['skipped']++;
            }
        }

        // Association automatique des nouveaux visages (seuils stricts,
        // garde-fous) — seulement si le moteur InsightFace est actif.
        if (FaceEngine::enabled()) {
            foreach ($media->detectedFaces()->where('status', 'unmatched')->whereNull('person_id')->get() as $face) {
                $this->matcher->autoMatch($face, $media->user_id);
            }
        }

        return $stats;
    }

    /**
     * Appariement glouton visage existant ↔ détection : recouvrement (IoU)
     * décroissant ; une petite zone peut s'apparier si les centres sont
     * l'un dans l'autre (les boîtes face-api sont plus larges).
     *
     * @return array<int,int> index existant => index détection
     */
    private function pair(array $existing, array $detections): array
    {
        $candidates = [];
        foreach ($existing as $i => $face) {
            if (! is_array($face->bounding_box)) {
                continue;
            }
            foreach ($detections as $j => $d) {
                $iou = $this->iou($face->bounding_box, $d['box']);
                if ($iou >= 0.25 || ($iou >= 0.1 && $this->centersInside($face->bounding_box, $d['box']))) {
                    $candidates[] = [$iou, $i, $j];
                }
            }
        }
        usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);

        $pairs = [];
        $taken = [];
        foreach ($candidates as [, $i, $j]) {
            if (! isset($pairs[$i]) && ! isset($taken[$j])) {
                $pairs[$i] = $j;
                $taken[$j] = true;
            }
        }

        return $pairs;
    }

    private function iou(array $a, array $b): float
    {
        $ix = max(0, min($a['x'] + $a['width'], $b['x'] + $b['width']) - max($a['x'], $b['x']));
        $iy = max(0, min($a['y'] + $a['height'], $b['y'] + $b['height']) - max($a['y'], $b['y']));
        $inter = $ix * $iy;
        $union = $a['width'] * $a['height'] + $b['width'] * $b['height'] - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }

    private function centersInside(array $a, array $b): bool
    {
        $inside = fn ($box, $x, $y) => $x >= $box['x'] && $x <= $box['x'] + $box['width'] && $y >= $box['y'] && $y <= $box['y'] + $box['height'];

        return $inside($a, $b['x'] + $b['width'] / 2, $b['y'] + $b['height'] / 2)
            && $inside($b, $a['x'] + $a['width'] / 2, $a['y'] + $a['height'] / 2);
    }
}
