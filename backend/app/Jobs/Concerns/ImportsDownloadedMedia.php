<?php

namespace App\Jobs\Concerns;

use App\Models\Album;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

/**
 * Import d'un fichier téléchargé depuis un service externe (Google Photos,
 * Google Drive) : anti-doublon, création du média, rattachement aux cibles.
 *
 * Attend $this->userId, $this->personId et $this->albumId.
 */
trait ImportsDownloadedMedia
{
    /**
     * Crée le média à partir du fichier temporaire (toujours supprimé ensuite).
     * Idempotent : un contenu déjà présent chez cet utilisateur renvoie le
     * média existant (protège contre les retries et les re-sélections).
     */
    protected function storeDownloadedFile(MediaService $mediaService, string $tempPath, string $filename, ?string $mimeType): Media
    {
        // Déduplication fiable par empreinte de contenu (indépendante du nom).
        // Fallback sur le nom pour les médias existants sans hash (legacy) afin
        // de préserver l'idempotence des ré-imports avant le backfill.
        $hash = hash_file('sha256', $tempPath);
        $existing = Media::where('user_id', $this->userId)
            ->where(function ($q) use ($hash, $filename) {
                $q->where('content_hash', $hash)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('content_hash')->where('original_name', $filename));
            })
            ->first();

        if ($existing) {
            @unlink($tempPath);
            Log::info(class_basename($this) . ': doublon ignoré', ['filename' => $filename, 'hash' => $hash]);

            return $existing;
        }

        try {
            $uploadedFile = new UploadedFile(
                $tempPath,
                $filename,
                $mimeType,
                null,
                true // test mode : accepte un fichier hors upload HTTP
            );

            return $mediaService->uploadMedia($uploadedFile, $this->userId);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Nom de fichier temporaire sûr dérivé du nom d'origine.
     */
    protected function tempPathFor(string $prefix, string $filename): string
    {
        return sys_get_temp_dir() . "/{$prefix}_" . uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
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

                // Première photo de l'album → devient la couverture par défaut.
                if (! $album->cover_media_id) {
                    $album->update(['cover_media_id' => $media->id]);
                }
            }
        }
    }
}
