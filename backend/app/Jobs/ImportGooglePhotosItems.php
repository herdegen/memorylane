<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Importe les éléments choisis dans le Picker Google Photos.
 *
 * Télécharge chaque élément sélectionné (qualité originale), le crée via
 * MediaService (ce qui déclenche les jobs habituels : conversions, EXIF,
 * Vision), puis le rattache à la personne et/ou l'album demandés.
 */
class ImportGooglePhotosItems implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    protected const PICKER_BASE_URL = 'https://photospicker.googleapis.com/v1';

    public $tries = 2;

    public $timeout = 3600;

    public function __construct(
        public string $userId,
        public string $accessToken,
        public string $pickerSessionId,
        public ?string $personId = null,
        public ?string $albumId = null,
    ) {}

    public function handle(MediaService $mediaService): void
    {
        Log::info('ImportGooglePhotosItems: Starting', [
            'user_id' => $this->userId,
            'session' => $this->pickerSessionId,
        ]);

        $imported = 0;
        $pageToken = null;

        do {
            $response = Http::withToken($this->accessToken)
                ->get(self::PICKER_BASE_URL . '/mediaItems', array_filter([
                    'sessionId' => $this->pickerSessionId,
                    'pageSize' => 25,
                    'pageToken' => $pageToken,
                ]));

            if (! $response->successful()) {
                Log::error('ImportGooglePhotosItems: Failed to list media items', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('Google Photos: échec de la liste des éléments sélectionnés.');
            }

            foreach ($response->json('mediaItems', []) as $item) {
                try {
                    $media = $this->importItem($mediaService, $item);
                    if ($media) {
                        $this->attachTargets($media);
                        $imported++;
                    }
                } catch (\Exception $e) {
                    Log::warning('ImportGooglePhotosItems: Item import failed', [
                        'item_id' => $item['id'] ?? null,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $pageToken = $response->json('nextPageToken');
        } while ($pageToken);

        // La session Picker ne sert plus : on la supprime côté Google
        Http::withToken($this->accessToken)
            ->delete(self::PICKER_BASE_URL . '/sessions/' . $this->pickerSessionId);

        Log::info('ImportGooglePhotosItems: Completed', [
            'user_id' => $this->userId,
            'imported' => $imported,
        ]);
    }

    /**
     * Télécharge un élément (qualité originale) et le crée en Media.
     */
    protected function importItem(MediaService $mediaService, array $item): ?Media
    {
        $file = $item['mediaFile'] ?? null;
        if (! $file || empty($file['baseUrl'])) {
            return null;
        }

        $isVideo = str_starts_with($file['mimeType'] ?? '', 'video/');
        // '=d' télécharge l'original ; '=dv' pour la vidéo
        $downloadUrl = $file['baseUrl'] . ($isVideo ? '=dv' : '=d');

        $filename = $file['filename'] ?? ('google-photos-' . ($item['id'] ?? uniqid()));
        $tempPath = sys_get_temp_dir() . '/gphotos_' . uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        $response = Http::withToken($this->accessToken)
            ->timeout(300)
            ->sink($tempPath)
            ->get($downloadUrl);

        if (! $response->successful() || ! filesize($tempPath)) {
            @unlink($tempPath);
            throw new \RuntimeException("Téléchargement échoué pour {$filename}");
        }

        try {
            $uploadedFile = new UploadedFile(
                $tempPath,
                $filename,
                $file['mimeType'] ?? null,
                null,
                true // test mode : accepte un fichier hors upload HTTP
            );

            return $mediaService->uploadMedia($uploadedFile, $this->userId);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Rattache le média importé à la personne et/ou l'album demandés.
     */
    protected function attachTargets(Media $media): void
    {
        if ($this->personId) {
            $media->people()->syncWithoutDetaching([$this->personId]);
        }

        if ($this->albumId) {
            $album = Album::find($this->albumId);
            if ($album) {
                $nextOrder = ($album->media()->max('album_media.order') ?? 0) + 1;
                $album->media()->syncWithoutDetaching([$media->id => ['order' => $nextOrder]]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ImportGooglePhotosItems: Job failed permanently', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
