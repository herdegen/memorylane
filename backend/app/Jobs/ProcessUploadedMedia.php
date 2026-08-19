<?php

namespace App\Jobs;

use App\Jobs\Concerns\DownloadsMediaToTemp;
use App\Models\Media;
use App\Models\MediaMetadata;
use App\Services\ExifExtractor;
use App\Services\S3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessUploadedMedia implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, DownloadsMediaToTemp;

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

            // L'upload multipart direct navigateur->S3 crée le Media sans
            // empreinte ni validation du contenu réel (le serveur n'a jamais
            // vu les octets) : on comble ici les deux.
            $needsIntegrityCheck = $this->media->content_hash === null;

            if ($this->media->type === 'photo' || $needsIntegrityCheck) {
                $tempPath = $this->downloadFileToTemp($s3Service);

                if (! $tempPath) {
                    Log::warning('ProcessUploadedMedia: Failed to download file from S3', [
                        'media_id' => $this->media->id,
                        'file_path' => $this->media->file_path,
                    ]);

                    return;
                }

                try {
                    if ($needsIntegrityCheck && ! $this->verifyAndFingerprint($tempPath, $s3Service)) {
                        return; // contenu rejeté : média purgé
                    }

                    if ($this->media->type === 'photo') {
                        $takenAt = $this->extractExifData($exifExtractor, $tempPath);

                        // Update the taken_at timestamp if we extracted it from EXIF
                        if ($takenAt) {
                            $this->media->taken_at = $takenAt;
                            $this->media->save();
                        }
                    }
                } finally {
                    @unlink($tempPath);
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
     * Calcule l'empreinte sha256 manquante et vérifie que le contenu réel
     * correspond à un type autorisé (l'upload direct ne valide que le MIME
     * annoncé par le client).
     *
     * @return bool false si le média a été rejeté (et purgé)
     */
    protected function verifyAndFingerprint(string $tempPath, S3Service $s3Service): bool
    {
        $realMime = @mime_content_type($tempPath) ?: null;

        // Types au contenu actif (exécutable/interprétable par un navigateur) :
        // jamais acceptables pour une galerie photo/vidéo.
        $isDangerous = $realMime !== null && (
            str_starts_with($realMime, 'text/')
            || in_array($realMime, ['image/svg+xml', 'application/xml', 'application/xhtml+xml', 'application/javascript'], true)
        );

        if ($isDangerous) {
            Log::warning('ProcessUploadedMedia: contenu réel refusé, média purgé', [
                'media_id' => $this->media->id,
                'declared_mime' => $this->media->mime_type,
                'real_mime' => $realMime,
            ]);

            $s3Service->delete($this->media->file_path);
            $this->media->forceDelete();

            return false;
        }

        $updates = ['content_hash' => @hash_file('sha256', $tempPath) ?: null];

        // Réaligne le MIME (et le type photo/vidéo) sur le contenu réel quand
        // la détection est fiable — octet-stream = indéterminé, on garde le déclaré.
        if ($realMime && $realMime !== 'application/octet-stream' && $realMime !== $this->media->mime_type) {
            $updates['mime_type'] = $realMime;

            $realType = str_starts_with($realMime, 'image/') ? 'photo'
                : (str_starts_with($realMime, 'video/') ? 'video' : $this->media->type);
            if ($realType !== $this->media->type) {
                $updates['type'] = $realType;
            }
        }

        $this->media->fill($updates)->save();

        return true;
    }

    /**
     * Extract EXIF data from the media file and store it in the database.
     *
     * @param string $tempPath Chemin local du fichier déjà téléchargé
     * @return string|null The taken_at timestamp from EXIF data, or null
     */
    protected function extractExifData(ExifExtractor $exifExtractor, string $tempPath): ?string
    {
        try {
            // Extract EXIF data
            $exifData = $exifExtractor->extract($tempPath);

            // Save metadata to database. La géoloc n'écrase jamais une valeur
            // existante avec du vide : certaines sources (import Takeout) la
            // fournissent hors EXIF, avant ou après ce job.
            $existing = MediaMetadata::where('media_id', $this->media->id)->first();

            $values = [
                'exif_data' => $exifData['exif_data'],
                'camera_make' => $exifData['camera_make'],
                'camera_model' => $exifData['camera_model'],
                'iso' => $exifData['iso'],
                'aperture' => $exifData['aperture'],
                'shutter_speed' => $exifData['shutter_speed'],
                'focal_length' => $exifData['focal_length'],
                'latitude' => $exifData['latitude'] ?? $existing?->latitude,
                'longitude' => $exifData['longitude'] ?? $existing?->longitude,
                'altitude' => $exifData['altitude'] ?? $existing?->altitude,
            ];

            MediaMetadata::updateOrCreate(
                ['media_id' => $this->media->id],
                $values
            );

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
