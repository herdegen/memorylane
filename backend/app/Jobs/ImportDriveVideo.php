<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\GoogleOAuth;
use App\Services\MediaService;
use App\Services\VideoOptimizer;
use Illuminate\Support\Facades\Log;

/**
 * Une vidéo choisie dans Google Drive : téléchargée, optimisée si besoin
 * (VideoOptimizer — l'original reste sur le Drive, MemoryLane ne garde que
 * la version optimisée), puis créée comme un envoi classique. File « video »
 * à part (un travailleur, délai long) : un réencodage peut durer de
 * quelques minutes à plus d'une heure.
 */
class ImportDriveVideo extends ImportGoogleDriveFiles
{
    public $tries = 1;

    public $timeout = 4 * 3600 + 600;

    public function __construct(
        string $userId,
        string $accessToken,
        ?string $refreshToken,
        public string $fileId,
        public string $filename,
        public string $mimeType,
        ?string $personId = null,
        ?string $albumId = null,
        ?string $householdId = null,
    ) {
        parent::__construct($userId, $accessToken, $refreshToken, [$fileId], $personId, $albumId, $householdId);
        $this->onConnection('redis-long')->onQueue('video');
    }

    public function handle(MediaService $mediaService, GoogleOAuth $oauth, ?VideoOptimizer $optimizer = null): void
    {
        $optimizer ??= app(VideoOptimizer::class);
        $source = $this->tempPathFor('gdrive', $this->filename);
        $response = $this->driveGet($oauth, $this->fileId, ['alt' => 'media'], $source);
        if (! $response->successful() || ! @filesize($source)) {
            @unlink($source);
            throw new \RuntimeException("Téléchargement échoué pour {$this->filename} ({$response->status()})");
        }

        try {
            $probe = $optimizer->probe($source);
            $optimize = $optimizer->needsOptimization($probe);
            $name = $optimize ? pathinfo($this->filename, PATHINFO_FILENAME) . '.mp4' : $this->filename;

            // Déjà importée (même nom, même durée) : pas de nouveau réencodage.
            $existing = Media::where('user_id', $this->userId)->where('type', 'video')->where('original_name', $name)
                ->whereBetween('duration', [(int) floor($probe['duration']) - 1, (int) ceil($probe['duration']) + 1])->first();
            if ($existing) {
                Log::info('ImportDriveVideo: déjà importée', ['file' => $this->filename]);
                $this->attachTargets($existing);

                return;
            }

            $path = $source;
            if ($optimize) {
                $path = $this->tempPathFor('gdrive-opt', $name);
                Log::info('ImportDriveVideo: optimisation', ['file' => $this->filename, 'codec' => $probe['codec'], 'height' => $probe['height'], 'bitrate' => $probe['bitrate'], 'interlaced' => $probe['interlaced']]);
                $optimizer->optimize($source, $path, $probe);
                Log::info('ImportDriveVideo: optimisée', ['file' => $this->filename, 'before' => filesize($source), 'after' => filesize($path)]);
                // Pas plus léger qu'un original déjà lisible partout : on garde l'original.
                if ($optimizer->keepSource($probe, $source, $path)) {
                    @unlink($path);
                    [$path, $name, $optimize] = [$source, $this->filename, false];
                }
            }

            $media = $this->storeDownloadedFile($mediaService, $path, $name, $optimize ? 'video/mp4' : $this->mimeType);
            // Durée connue tout de suite (les métadonnées précises suivent) :
            // un réimport immédiat est reconnu.
            if ($media->duration === null && $probe['duration'] > 0) {
                $media->forceFill(['duration' => (int) round($probe['duration'])])->save();
            }
            $this->attachTargets($media);
        } finally {
            @unlink($source);
        }
    }
}
