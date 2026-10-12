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

    /** Marqueur des anciens cadres masqués faute de visage (réversible). */
    public const UNCONFIRMED = 'faceapi-unconfirmed';

    /**
     * Ancien cadre face-api ni identifié ni dessiné à la main : sans visage
     * retrouvé par InsightFace, on le considère comme un faux visage.
     */
    public static function isUnconfirmedGuess(DetectedFace $face): bool
    {
        return $face->person_id === null
            && $face->provider === 'faceapi'
            && in_array($face->status, ['unmatched', 'rejected'], true);
    }

    /**
     * Empreinte d'une zone : visage retrouvé dans la zone élargie
     * (insightface-box) ou simple recadrage, aucun visage vu (insightface-crop).
     */
    public static function boxEngine(?string $source): string
    {
        return $source === 'crop' ? 'insightface-crop' : 'insightface-box';
    }

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

        $stats = ['updated' => 0, 'kept' => 0, 'hidden' => 0, 'added' => 0, 'skipped' => 0];
        $added = [];

        DB::transaction(function () use ($media, $existing, $detections, $pairs, &$stats, &$added) {
            foreach ($existing as $i => $face) {
                if (isset($pairs[$i])) {
                    $d = $detections[$pairs[$i]];
                    $face->forceFill(['embedding_v2' => $d['embedding'], 'age_estimate' => $d['age'], 'engine' => 'insightface'])->save();
                    $stats['updated']++;
                } elseif (self::isUnconfirmedGuess($face)) {
                    // Ancien cadre non identifié qu'InsightFace ne voit pas :
                    // presque toujours un faux visage (dos, profil, motif).
                    // Masqué (« ignoré »), jamais supprimé.
                    $face->forceFill(['status' => 'dismissed', 'engine' => self::UNCONFIRMED])->save();
                    $stats['hidden']++;
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
                $missed[$k]->forceFill(['embedding_v2' => $embedded['embedding'], 'age_estimate' => $embedded['age'], 'engine' => self::boxEngine($embedded['source'])])->save();
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
                if (self::sameFace($face->bounding_box, $d['box'])) {
                    $candidates[] = [self::iou($face->bounding_box, $d['box']), $i, $j];
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

    /**
     * Même visage : bon recouvrement, ou centre de la nouvelle détection dans
     * l'ancien cadre (ceux de face-api, cheveux compris, sont plus grands).
     */
    public static function sameFace(array $old, array $new): bool
    {
        $iou = self::iou($old, $new);
        $cx = $new['x'] + $new['width'] / 2;
        $cy = $new['y'] + $new['height'] / 2;
        $inside = $cx >= $old['x'] && $cx <= $old['x'] + $old['width'] && $cy >= $old['y'] && $cy <= $old['y'] + $old['height'];

        return $iou >= 0.25 || ($inside && $iou >= 0.05);
    }

    public static function iou(array $a, array $b): float
    {
        $ix = max(0, min($a['x'] + $a['width'], $b['x'] + $b['width']) - max($a['x'], $b['x']));
        $iy = max(0, min($a['y'] + $a['height'], $b['y'] + $b['height']) - max($a['y'], $b['y']));
        $inter = $ix * $iy;
        $union = $a['width'] * $a['height'] + $b['width'] * $b['height'] - $inter;

        return $union > 0 ? $inter / $union : 0.0;
    }
}
