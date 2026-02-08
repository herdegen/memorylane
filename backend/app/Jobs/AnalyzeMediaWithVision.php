<?php

namespace App\Jobs;

use App\Contracts\VisionServiceInterface;
use App\Jobs\Concerns\DownloadsMediaToTemp;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\MediaMetadata;
use App\Models\Tag;
use App\Services\S3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnalyzeMediaWithVision implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, DownloadsMediaToTemp;

    public $tries = 2;

    public $timeout = 120;

    public $backoff = [30, 120];

    public function __construct(
        public Media $media
    ) {
        //
    }

    public function handle(VisionServiceInterface $visionService, S3Service $s3Service): void
    {
        if ($this->media->type !== 'photo') {
            return;
        }

        if (! $visionService->isAvailable()) {
            Log::info('AnalyzeMediaWithVision: Vision service not available, skipping', [
                'media_id' => $this->media->id,
            ]);

            return;
        }

        $this->updateVisionStatus('processing');
        $tempPath = null;

        try {
            Log::info('AnalyzeMediaWithVision: Starting analysis', [
                'media_id' => $this->media->id,
                'provider' => $visionService->getProviderName(),
            ]);

            $tempPath = $this->downloadFileToTemp($s3Service);
            if (! $tempPath) {
                $this->updateVisionStatus('failed', 'File download failed');

                return;
            }

            $results = $visionService->analyze($tempPath);

            // Store detected faces
            $this->storeDetectedFaces($results['faces'], $visionService->getProviderName());

            // Store labels in media_metadata
            $this->storeLabels($results['labels']);

            // Auto-tag if enabled
            if (config('vision.auto_tag')) {
                $this->autoTagMedia($results['labels']);
            }

            // Update status to completed
            MediaMetadata::updateOrCreate(
                ['media_id' => $this->media->id],
                [
                    'vision_status' => 'completed',
                    'vision_provider' => $visionService->getProviderName(),
                    'vision_processed_at' => now(),
                    'vision_error' => null,
                    'vision_faces_count' => count($results['faces']),
                ]
            );

            Log::info('AnalyzeMediaWithVision: Analysis completed', [
                'media_id' => $this->media->id,
                'faces_count' => count($results['faces']),
                'labels_count' => count($results['labels']),
            ]);
        } catch (\Exception $e) {
            $this->updateVisionStatus('failed', $e->getMessage());

            Log::error('AnalyzeMediaWithVision: Analysis failed', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            if ($tempPath) {
                @unlink($tempPath);
            }
        }
    }

    private function updateVisionStatus(string $status, ?string $error = null): void
    {
        MediaMetadata::updateOrCreate(
            ['media_id' => $this->media->id],
            array_filter([
                'vision_status' => $status,
                'vision_error' => $error,
            ], fn ($v) => $v !== null)
        );
    }

    private function storeDetectedFaces(array $faces, string $provider): void
    {
        foreach ($faces as $face) {
            DetectedFace::create([
                'media_id' => $this->media->id,
                'bounding_box' => $face['bounding_box'],
                'confidence' => $face['confidence'],
                'landmarks' => $face['landmarks'] ?? null,
                'joy_likelihood' => $face['emotions']['joy'] ?? null,
                'sorrow_likelihood' => $face['emotions']['sorrow'] ?? null,
                'anger_likelihood' => $face['emotions']['anger'] ?? null,
                'surprise_likelihood' => $face['emotions']['surprise'] ?? null,
                'roll_angle' => $face['angles']['roll'] ?? null,
                'pan_angle' => $face['angles']['pan'] ?? null,
                'tilt_angle' => $face['angles']['tilt'] ?? null,
                'provider' => $provider,
                'status' => 'unmatched',
            ]);
        }
    }

    private function storeLabels(array $labels): void
    {
        MediaMetadata::updateOrCreate(
            ['media_id' => $this->media->id],
            ['vision_labels' => $labels]
        );
    }

    private function autoTagMedia(array $labels): void
    {
        $threshold = config('vision.thresholds.label_confidence', 0.70);

        foreach ($labels as $label) {
            if ($label['score'] < $threshold) {
                continue;
            }

            $slug = Str::slug($label['name']);
            if (empty($slug)) {
                continue;
            }

            $tag = Tag::firstOrCreate(
                ['slug' => $slug],
                [
                    'name' => ucfirst($label['name']),
                    'color' => '#8b5cf6',
                    'type' => 'ai',
                ]
            );

            if (! $this->media->tags()->where('tags.id', $tag->id)->exists()) {
                $this->media->tags()->attach($tag->id);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AnalyzeMediaWithVision: Job failed permanently', [
            'media_id' => $this->media->id,
            'error' => $exception->getMessage(),
        ]);

        $this->updateVisionStatus('failed', $exception->getMessage());
    }
}
