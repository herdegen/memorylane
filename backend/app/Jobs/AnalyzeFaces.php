<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\Vision\FaceEngine;
use App\Services\Vision\FaceReconciler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Analyse des visages d'un média par le service InsightFace, intégrée sans
 * rien perdre (FaceReconciler). File « faces » à part.
 */
class AnalyzeFaces implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [30, 180];

    public function __construct(public Media $media)
    {
        $this->onQueue('faces');
    }

    public function handle(FaceEngine $engine, FaceReconciler $reconciler): void
    {
        if (! in_array($this->media->type, ['photo', 'video'], true)) {
            return;
        }

        $detections = $engine->analyze($this->media);
        if ($detections === null) {
            Log::warning('AnalyzeFaces : image source introuvable', ['media_id' => $this->media->id]);

            return;
        }

        $stats = $reconciler->apply($this->media, $detections);
        Log::info('AnalyzeFaces', ['media_id' => $this->media->id, ...$stats]);
    }
}
