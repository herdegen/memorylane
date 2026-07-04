<?php

namespace App\Jobs;

use App\Models\Album;
use App\Models\Media;
use App\Models\MediaMetadata;
use App\Services\MediaService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Importe une archive Google Takeout (ZIP « Google Photos »).
 *
 * Contrairement à l'API (qui supprime le GPS), Takeout fournit pour chaque
 * média un JSON compagnon avec géolocalisation, date de prise de vue et
 * description. Les médias déjà présents (même nom de fichier) ne sont pas
 * dupliqués : ils sont ENRICHIS de ces métadonnées — c'est le chemin pour
 * récupérer la géoloc des photos importées via le Picker.
 *
 * Les albums Google Photos sont recréés : chaque dossier de l'archive qui
 * n'est pas chronologique (« Photos from 2021 ») devient un album MemoryLane
 * avec ses photos, son titre venant du metadata.json du dossier.
 */
class ImportTakeoutArchive implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    protected const MEDIA_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif',
        'mp4', 'mov', 'avi', 'mkv', 'webm', '3gp', 'm4v', 'mpg',
    ];

    public $tries = 1;

    public $timeout = 3600;

    public function __construct(
        public string $userId,
        public string $zipPath,
    ) {}

    public function handle(MediaService $mediaService): void
    {
        if (! file_exists($this->zipPath)) {
            Log::error('ImportTakeoutArchive: Archive not found', ['path' => $this->zipPath]);
            return;
        }

        $zip = new \ZipArchive();
        if ($zip->open($this->zipPath) !== true) {
            Log::error('ImportTakeoutArchive: Cannot open archive', ['path' => $this->zipPath]);
            return;
        }

        Log::info('ImportTakeoutArchive: Starting', [
            'user_id' => $this->userId,
            'entries' => $zip->numFiles,
        ]);

        $stats = ['imported' => 0, 'enriched' => 0, 'skipped' => 0, 'failed' => 0, 'albums' => 0];
        $seenNames = [];
        $albumMembers = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entryName = $zip->getNameIndex($i);
                $basename = basename($entryName);
                $extension = strtolower(pathinfo($basename, PATHINFO_EXTENSION));

                if (! in_array($extension, self::MEDIA_EXTENSIONS, true)) {
                    continue;
                }

                // Un dossier non chronologique = un album Google : on note
                // l'appartenance même pour les doublons inter-dossiers
                $albumFolder = $this->albumFolderFor($entryName);
                if ($albumFolder) {
                    $albumMembers[$albumFolder][] = $basename;
                }

                // Takeout duplique les photos présentes dans plusieurs albums
                if (isset($seenNames[$basename])) {
                    $stats['skipped']++;
                    continue;
                }
                $seenNames[$basename] = true;

                try {
                    $sidecar = $this->readSidecar($zip, $entryName);

                    $existing = Media::where('user_id', $this->userId)
                        ->where('original_name', $basename)
                        ->first();

                    if ($existing) {
                        $this->applySidecar($existing, $sidecar);
                        $stats['enriched']++;
                        continue;
                    }

                    $media = $this->importEntry($mediaService, $zip, $entryName, $basename);
                    if ($media) {
                        $this->applySidecar($media, $sidecar);
                        $stats['imported']++;
                    }
                } catch (\Exception $e) {
                    $stats['failed']++;
                    Log::warning('ImportTakeoutArchive: Entry failed', [
                        'entry' => $entryName,
                        'error' => $e->getMessage(),
                    ]);
                }

                if (($stats['imported'] + $stats['enriched']) % 25 === 0) {
                    Log::info('ImportTakeoutArchive: Progress', $stats);
                }
            }

            $this->recreateAlbums($zip, $albumMembers, $stats);
        } finally {
            $zip->close();
            @unlink($this->zipPath);
        }

        Log::info('ImportTakeoutArchive: Completed', $stats);
    }

    /**
     * Retourne le chemin du dossier si l'entrée appartient à un album Google,
     * null pour les dossiers chronologiques ou spéciaux.
     */
    protected function albumFolderFor(string $entryName): ?string
    {
        $dir = dirname($entryName);
        $folder = basename($dir);

        if ($folder === '.' || $folder === '') {
            return null;
        }

        // Dossiers chronologiques (« Photos from 2021 ») et spéciaux
        if (preg_match('/^photos (from|de|of|van|aus) \d{4}$/iu', $folder)) {
            return null;
        }
        if (preg_match('/^\d{4}(-\d{2})?$/', $folder)) {
            return null;
        }
        $special = ['google photos', 'takeout', 'trash', 'corbeille', 'bin', 'archive', 'failed videos'];
        if (in_array(mb_strtolower($folder), $special, true)) {
            return null;
        }

        return $dir;
    }

    /**
     * Recrée les albums Google Photos : un album MemoryLane par dossier,
     * titre lu dans le metadata.json du dossier quand il existe.
     */
    protected function recreateAlbums(\ZipArchive $zip, array $albumMembers, array &$stats): void
    {
        foreach ($albumMembers as $folder => $basenames) {
            $title = $this->albumTitle($zip, $folder);
            if (! $title) {
                continue;
            }

            $album = Album::firstOrCreate(
                ['user_id' => $this->userId, 'name' => $title],
                ['is_public' => false]
            );

            $mediaIds = Media::where('user_id', $this->userId)
                ->whereIn('original_name', array_unique($basenames))
                ->pluck('id');

            if ($mediaIds->isEmpty()) {
                continue;
            }

            $order = $album->media()->max('album_media.order') ?? 0;
            $attach = [];
            foreach ($mediaIds as $id) {
                $attach[$id] = ['order' => ++$order];
            }
            $album->media()->syncWithoutDetaching($attach);

            if (! $album->cover_media_id) {
                $album->update(['cover_media_id' => $mediaIds->first()]);
            }

            $stats['albums']++;

            Log::info('ImportTakeoutArchive: Album recreated', [
                'album' => $title,
                'media_count' => $mediaIds->count(),
            ]);
        }
    }

    /**
     * Titre de l'album : le metadata.json du dossier (titre réel, accents
     * compris), sinon le nom du dossier.
     */
    protected function albumTitle(\ZipArchive $zip, string $folder): ?string
    {
        foreach (['metadata.json', 'métadonnées.json'] as $name) {
            $content = $zip->getFromName($folder . '/' . $name);
            if ($content !== false) {
                $data = json_decode($content, true);
                if (! empty($data['title'])) {
                    return $data['title'];
                }
            }
        }

        return basename($folder) ?: null;
    }

    /**
     * Lit le JSON compagnon d'un média (géoloc, date, description).
     */
    protected function readSidecar(\ZipArchive $zip, string $entryName): ?array
    {
        // Formats rencontrés : X.jpg.supplemental-metadata.json (récent),
        // X.jpg.json (anciens exports)
        foreach (["{$entryName}.supplemental-metadata.json", "{$entryName}.json"] as $candidate) {
            $content = $zip->getFromName($candidate);
            if ($content !== false) {
                $data = json_decode($content, true);
                return is_array($data) ? $data : null;
            }
        }

        return null;
    }

    /**
     * Extrait un média du ZIP (en streaming) et le crée via MediaService.
     */
    protected function importEntry(MediaService $mediaService, \ZipArchive $zip, string $entryName, string $basename): ?Media
    {
        $stream = $zip->getStream($entryName);
        if (! $stream) {
            return null;
        }

        $tempPath = sys_get_temp_dir() . '/takeout_' . uniqid() . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);
        $out = fopen($tempPath, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);

        try {
            $uploadedFile = new UploadedFile($tempPath, $basename, null, null, true);

            return $mediaService->uploadMedia($uploadedFile, $this->userId);
        } finally {
            @unlink($tempPath);
        }
    }

    /**
     * Applique les métadonnées du JSON Takeout : date de prise de vue,
     * description, et surtout géolocalisation (jamais écrasée si déjà là).
     */
    protected function applySidecar(Media $media, ?array $sidecar): void
    {
        if (! $sidecar) {
            return;
        }

        $updates = [];

        $timestamp = $sidecar['photoTakenTime']['timestamp'] ?? null;
        if ($timestamp && ! $media->taken_at) {
            $updates['taken_at'] = date('Y-m-d H:i:s', (int) $timestamp);
        }

        if (! empty($sidecar['description']) && empty($media->description)) {
            $updates['description'] = $sidecar['description'];
        }

        if ($updates) {
            $media->update($updates);
        }

        // geoData reflète les corrections faites dans Google Photos ;
        // geoDataExif est la position d'origine. 0.0/0.0 = non renseigné.
        $geo = $this->pickGeo($sidecar['geoData'] ?? null) ?? $this->pickGeo($sidecar['geoDataExif'] ?? null);
        if ($geo) {
            $metadata = MediaMetadata::firstOrNew(['media_id' => $media->id]);
            if (! $metadata->latitude || ! $metadata->longitude) {
                $metadata->latitude = $geo['latitude'];
                $metadata->longitude = $geo['longitude'];
                if (! empty($geo['altitude'])) {
                    $metadata->altitude = $geo['altitude'];
                }
                $metadata->save();
            }
        }
    }

    protected function pickGeo(?array $geo): ?array
    {
        if (! $geo || empty($geo['latitude']) || empty($geo['longitude'])) {
            return null;
        }

        return $geo;
    }

    public function failed(\Throwable $exception): void
    {
        @unlink($this->zipPath);

        Log::error('ImportTakeoutArchive: Job failed permanently', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }
}
