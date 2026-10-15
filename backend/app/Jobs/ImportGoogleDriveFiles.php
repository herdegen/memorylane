<?php

namespace App\Jobs;

use App\Jobs\Concerns\ImportsDownloadedMedia;
use App\Models\Media;
use App\Services\GoogleOAuth;
use App\Services\MediaService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Importe les fichiers choisis dans le sélecteur Google Drive.
 *
 * Télécharge chaque fichier (photo ou vidéo, tel qu'il est stocké dans le
 * Drive, donc en pleine définition), le crée via MediaService (conversions,
 * EXIF, visages comme un envoi classique), puis le rattache à la personne
 * et/ou l'album demandés. Le jeton d'accès expire au bout d'une heure : il est
 * renouvelé avec le refresh token pour les longues sélections. Chiffré en file
 * puisqu'il transporte ces jetons.
 */
class ImportGoogleDriveFiles implements ShouldQueue, ShouldBeEncrypted
{
    use Queueable, InteractsWithQueue, SerializesModels, ImportsDownloadedMedia;

    protected const FILES_URL = 'https://www.googleapis.com/drive/v3/files/';

    /** Vidéos confiées à ImportDriveVideo (optimisées à part). */
    protected int $queuedVideos = 0;

    public $tries = 2;

    public $timeout = 3600;

    /**
     * @param  string[]  $fileIds  identifiants Drive renvoyés par le sélecteur
     */
    public function __construct(
        public string $userId,
        public string $accessToken,
        public ?string $refreshToken,
        public array $fileIds,
        public ?string $personId = null,
        public ?string $albumId = null,
        public ?string $householdId = null,
    ) {}

    public function handle(MediaService $mediaService, GoogleOAuth $oauth): void
    {
        Log::info('ImportGoogleDriveFiles: Starting', [
            'user_id' => $this->userId,
            'files' => count($this->fileIds),
        ]);

        $imported = 0;
        $skipped = 0;

        foreach ($this->fileIds as $fileId) {
            try {
                $queued = $this->queuedVideos;
                $media = $this->importFile($mediaService, $oauth, $fileId);
                if ($media) {
                    $this->attachTargets($media);
                    $imported++;
                } elseif ($this->queuedVideos === $queued) {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $skipped++;
                Log::warning('ImportGoogleDriveFiles: File import failed', [
                    'file_id' => $fileId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('ImportGoogleDriveFiles: Completed', [
            'user_id' => $this->userId,
            'imported' => $imported,
            'skipped' => $skipped,
        ]);

        Cache::put(self::statusKey($this->userId), [
            'finished' => true,
            'failed' => false,
            'imported' => $imported,
            'skipped' => $skipped,
            'videos' => $this->queuedVideos,
        ], now()->addHours(2));
    }

    /**
     * Clé de cache de l'état terminal de l'import (un import à la fois par user).
     */
    public static function statusKey(string $userId): string
    {
        return "gdrive_import:{$userId}";
    }

    /**
     * Télécharge un fichier Drive et le crée en Media. Renvoie null pour ce qui
     * n'est pas une photo ou une vidéo acceptée (le type vient de Google, pas
     * du navigateur).
     */
    protected function importFile(MediaService $mediaService, GoogleOAuth $oauth, string $fileId): ?Media
    {
        $meta = $this->driveGet($oauth, $fileId, ['fields' => 'id,name,mimeType,size']);

        if (! $meta->successful()) {
            throw new \RuntimeException("Métadonnées inaccessibles ({$meta->status()})");
        }

        $mimeType = (string) $meta->json('mimeType');
        $filename = $meta->json('name') ?: "google-drive-{$fileId}";
        $isMedia = str_starts_with($mimeType, 'image/') || str_starts_with($mimeType, 'video/');

        if (! $isMedia || ! isset(MediaService::EXTENSIONS[$mimeType])) {
            Log::info('ImportGoogleDriveFiles: type non pris en charge', ['file' => $filename, 'mime' => $mimeType]);

            return null;
        }

        // Même plafond que l'envoi direct (config media.upload_max_bytes).
        if ((int) $meta->json('size') > (int) config('media.upload_max_bytes')) {
            Log::info('ImportGoogleDriveFiles: fichier trop lourd', ['file' => $filename, 'size' => $meta->json('size')]);

            return null;
        }

        // Vidéo : téléchargée et optimisée à part (file « video », long).
        if (str_starts_with($mimeType, 'video/')) {
            ImportDriveVideo::dispatch($this->userId, $this->accessToken, $this->refreshToken, $fileId, $filename, $mimeType, $this->personId, $this->albumId, $this->householdId);
            $this->queuedVideos++;

            return null;
        }

        $tempPath = $this->tempPathFor('gdrive', $filename);
        $response = $this->driveGet($oauth, $fileId, ['alt' => 'media'], $tempPath);

        if (! $response->successful() || ! @filesize($tempPath)) {
            @unlink($tempPath);
            throw new \RuntimeException("Téléchargement échoué pour {$filename} ({$response->status()})");
        }

        return $this->storeDownloadedFile($mediaService, $tempPath, $filename, $mimeType);
    }

    /**
     * GET sur un fichier Drive ; sur 401 (jeton expiré), renouvelle le jeton
     * une fois et rejoue la requête.
     */
    protected function driveGet(GoogleOAuth $oauth, string $fileId, array $query, ?string $sink = null): Response
    {
        $send = fn () => Http::withToken($this->accessToken)
            ->timeout($sink ? 900 : 30)
            ->when($sink, fn ($http) => $http->sink($sink))
            ->get(self::FILES_URL . rawurlencode($fileId), $query + ['supportsAllDrives' => 'true']);

        $response = $send();

        if ($response->status() === 401 && $this->refreshToken) {
            $token = $oauth->refresh($this->refreshToken);
            if ($token) {
                $this->accessToken = $token['access_token'];
                $response = $send();
            }
        }

        return $response;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ImportGoogleDriveFiles: Job failed permanently', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);

        Cache::put(self::statusKey($this->userId), [
            'finished' => true,
            'failed' => true,
            'imported' => null,
        ], now()->addHours(2));
    }
}
