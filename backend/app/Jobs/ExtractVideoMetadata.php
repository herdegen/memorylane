<?php

namespace App\Jobs;

use App\Jobs\Concerns\DownloadsMediaToTemp;
use App\Models\Media;
use App\Services\S3Service;
use App\Services\VideoMetadataService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Retraite les métadonnées techniques d'une vidéo existante.
 *
 * N'est plus dispatché à l'upload : l'extraction se fait dans
 * GenerateMediaConversions sur le fichier déjà téléchargé (évite un
 * second téléchargement S3). Ce job reste utile pour re-extraire les
 * métadonnées d'une vidéo sans regénérer ses conversions (backfill, fix).
 */
class ExtractVideoMetadata implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, DownloadsMediaToTemp;

    public $tries = 3;

    public $timeout = 300;

    public function __construct(
        public Media $media
    ) {}

    public function handle(S3Service $s3Service, VideoMetadataService $videoMetadataService): void
    {
        if ($this->media->type !== 'video') {
            return;
        }

        try {
            Log::info('ExtractVideoMetadata: Starting', [
                'media_id' => $this->media->id,
                'file_path' => $this->media->file_path,
            ]);

            $tempPath = $this->downloadFileToTemp($s3Service);

            if (! $tempPath) {
                Log::warning('ExtractVideoMetadata: Failed to download file from S3', [
                    'media_id' => $this->media->id,
                ]);
                return;
            }

            try {
                $videoMetadataService->extractFromFile($this->media, $tempPath);

                Log::info('ExtractVideoMetadata: Completed', [
                    'media_id'    => $this->media->id,
                    'duration'    => $this->media->duration,
                    'video_codec' => $this->media->video_codec,
                    'fps'         => $this->media->fps,
                    'bitrate'     => $this->media->bitrate,
                ]);
            } finally {
                @unlink($tempPath);
            }
        } catch (\Exception $e) {
            Log::error('ExtractVideoMetadata: Failed', [
                'media_id' => $this->media->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractVideoMetadata: Job failed permanently', [
            'media_id' => $this->media->id,
            'error'    => $exception->getMessage(),
        ]);
    }
}
