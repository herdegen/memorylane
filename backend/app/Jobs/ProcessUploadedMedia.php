<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\MediaMetadata;
use App\Services\ExifExtractor;
use App\Services\S3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessUploadedMedia implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Media $media
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(ExifExtractor $exifExtractor, S3Service $s3Service): void
    {
        try {
            Log::info('ProcessUploadedMedia: Starting processing', [
                'media_id' => $this->media->id,
                'type' => $this->media->type,
                'file_path' => $this->media->file_path,
            ]);

            // Only process photos for EXIF data extraction
            if ($this->media->type === 'photo') {
                $takenAt = $this->extractExifData($exifExtractor, $s3Service);

                // Update the taken_at timestamp if we extracted it from EXIF
                if ($takenAt) {
                    $this->media->taken_at = $takenAt;
                    $this->media->save();
                }
            }

            Log::info('ProcessUploadedMedia: Processing completed', [
                'media_id' => $this->media->id,
            ]);
        } catch (\Exception $e) {
            Log::error('ProcessUploadedMedia: Processing failed', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Extract EXIF data from the media file and store it in the database.
     *
     * @param ExifExtractor $exifExtractor
     * @param S3Service $s3Service
     * @return string|null The taken_at timestamp from EXIF data, or null
     */
    protected function extractExifData(ExifExtractor $exifExtractor, S3Service $s3Service): ?string
    {
        try {
            // Download file from S3 to temporary location
            $tempPath = $this->downloadFileToTemp($s3Service);

            if (!$tempPath) {
                Log::warning('ProcessUploadedMedia: Failed to download file from S3', [
                    'media_id' => $this->media->id,
                    'file_path' => $this->media->file_path,
                ]);
                return null;
            }

            // Extract EXIF data
            $exifData = $exifExtractor->extract($tempPath);

            // Save metadata to database
            MediaMetadata::updateOrCreate(
                ['media_id' => $this->media->id],
                [
                    'exif_data' => $exifData['exif_data'],
                    'camera_make' => $exifData['camera_make'],
                    'camera_model' => $exifData['camera_model'],
                    'iso' => $exifData['iso'],
                    'aperture' => $exifData['aperture'],
                    'shutter_speed' => $exifData['shutter_speed'],
                    'focal_length' => $exifData['focal_length'],
                    'latitude' => $exifData['latitude'],
                    'longitude' => $exifData['longitude'],
                    'altitude' => $exifData['altitude'],
                ]
            );

            // Clean up temporary file
            @unlink($tempPath);

            Log::info('ProcessUploadedMedia: EXIF data extracted and saved', [
                'media_id' => $this->media->id,
                'has_gps' => !empty($exifData['latitude']) && !empty($exifData['longitude']),
                'camera' => $exifData['camera_make'] . ' ' . $exifData['camera_model'],
            ]);

            // Return the taken_at timestamp
            return $exifData['taken_at'] ?? null;
        } catch (\Exception $e) {
            Log::error('ProcessUploadedMedia: EXIF extraction failed', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
            ]);
            // Don't rethrow - EXIF extraction is not critical
            return null;
        }
    }

    /**
     * Download file from S3 to a temporary location.
     *
     * @param S3Service $s3Service
     * @return string|null The temporary file path, or null on failure
     */
    protected function downloadFileToTemp(S3Service $s3Service): ?string
    {
        try {
            $disk = Storage::disk($s3Service->getDisk());

            if (!$disk->exists($this->media->file_path)) {
                Log::error('ProcessUploadedMedia: File does not exist in S3', [
                    'media_id' => $this->media->id,
                    'file_path' => $this->media->file_path,
                ]);
                return null;
            }

            // Create temporary file
            $tempPath = sys_get_temp_dir() . '/media_' . $this->media->id . '_' . uniqid() . '.tmp';

            // Download file contents from S3
            $contents = $disk->get($this->media->file_path);

            // Write to temporary file
            file_put_contents($tempPath, $contents);

            return $tempPath;
        } catch (\Exception $e) {
            Log::error('ProcessUploadedMedia: Failed to download file to temp', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessUploadedMedia: Job failed permanently', [
            'media_id' => $this->media->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
