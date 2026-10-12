<?php

namespace App\Console\Commands;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Services\Vision\FaceEngine;
use App\Services\Vision\FaceReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Consolidation après la réanalyse InsightFace, pour les anciens cadres
 * face-api qu'elle n'avait pas rapprochés (cadres plus grands, cheveux
 * compris). Rien n'est supprimé, aucune identification n'est retirée :
 *
 *  1. fusion : visage ajouté par InsightFace par-dessus un ancien cadre →
 *     l'ancien cadre reçoit son empreinte, le doublon est masqué ;
 *  2. ancien cadre non identifié (ni dessiné à la main) toujours sans
 *     visage → masqué (faux visage : dos, profil, motif) ;
 *  3. ancien cadre identifié sans visage → empreinte de sa zone ; si aucun
 *     visage n'y est vu, il remonte dans « Identifications à vérifier ».
 */
class ConsolidateFaces extends Command
{
    protected $signature = 'faces:consolidate {--dry-run : compter sans rien modifier}';

    protected $description = 'Fusionne les doublons de cadres, masque les faux visages, signale les identifiés sans visage';

    private const UNCONFIRMED_ENGINES = ['insightface-box', 'insightface-crop'];

    public function handle(FaceEngine $engine): int
    {
        $dry = $this->option('dry-run');
        $merged = 0;
        $hidden = 0;
        $toCheck = collect();

        $mediaIds = DetectedFace::whereIn('engine', self::UNCONFIRMED_ENGINES)->distinct()->pluck('media_id');
        foreach ($mediaIds as $mediaId) {
            $faces = DetectedFace::where('media_id', $mediaId)->get();
            $old = $faces->filter(fn ($f) => in_array($f->engine, self::UNCONFIRMED_ENGINES, true))->values();
            $added = $faces->filter(fn ($f) => $f->provider === 'insightface' && $f->engine === 'insightface'
                && $f->person_id === null && $f->status === 'unmatched')->values();

            // 1. Fusion : meilleur recouvrement d'abord.
            $pairs = [];
            foreach ($old as $i => $o) {
                foreach ($added as $j => $a) {
                    if (FaceReconciler::sameFace($o->bounding_box, $a->bounding_box)) {
                        $pairs[] = [FaceReconciler::iou($o->bounding_box, $a->bounding_box), $i, $j];
                    }
                }
            }
            usort($pairs, fn ($x, $y) => $y[0] <=> $x[0]);
            $usedOld = [];
            $usedAdded = [];
            foreach ($pairs as [, $i, $j]) {
                if (isset($usedOld[$i]) || isset($usedAdded[$j])) {
                    continue;
                }
                $usedOld[$i] = $usedAdded[$j] = true;
                $merged++;
                if (! $dry) {
                    $old[$i]->forceFill(['embedding_v2' => $added[$j]->embedding_v2, 'age_estimate' => $added[$j]->age_estimate, 'engine' => 'insightface'])->save();
                    $added[$j]->forceFill(['status' => 'dismissed', 'engine' => 'merged'])->save();
                }
            }

            foreach ($old as $i => $o) {
                if (isset($usedOld[$i])) {
                    continue;
                }
                if (FaceReconciler::isUnconfirmedGuess($o)) {
                    // 2. Faux visage non identifié : masqué.
                    $hidden++;
                    if (! $dry) {
                        $o->forceFill(['status' => 'dismissed', 'engine' => FaceReconciler::UNCONFIRMED])->save();
                    }
                } elseif ($o->person_id && $o->engine === 'insightface-box') {
                    $toCheck->push($o); // 3. identifié : on regarde s'il y a un visage
                }
            }
        }

        $this->info(($dry ? 'Simulation — ' : '')."doublons fusionnés : {$merged} | faux visages masqués : {$hidden} | identifiés à contrôler : {$toCheck->count()}");
        if ($dry || $toCheck->isEmpty()) {
            return self::SUCCESS;
        }

        $noFace = $this->checkIdentified($engine, $toCheck);
        $this->info("Identifiés sans visage visible (envoyés en revue) : {$noFace}.");

        return self::SUCCESS;
    }

    private function checkIdentified(FaceEngine $engine, Collection $faces): int
    {
        $noFace = 0;
        $bar = $this->output->createProgressBar($faces->groupBy('media_id')->count());
        foreach ($faces->groupBy('media_id') as $mediaId => $group) {
            $media = Media::withTrashed()->find($mediaId);
            $results = $media ? $engine->embedBoxes($media, $group->pluck('bounding_box')->all()) : [];
            foreach ($group->values() as $k => $face) {
                if ($embedded = $results[$k] ?? null) {
                    $face->forceFill(['embedding_v2' => $embedded['embedding'], 'engine' => FaceReconciler::boxEngine($embedded['source'])])->save();
                    $noFace += $embedded['source'] === 'crop' ? 1 : 0;
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();

        return $noFace;
    }
}
