<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Rétro-remplit content_hash (sha256) pour les médias importés/uploadés avant
 * l'ajout du hash, afin que la déduplication d'import soit pleinement fiable
 * (sans dépendre du fallback sur le nom). À lancer une fois.
 */
class BackfillMediaHashes extends Command
{
    protected $signature = 'media:backfill-hashes';

    protected $description = 'Calcule content_hash (sha256) pour les médias sans empreinte';

    public function handle(): int
    {
        $disk = config('filesystems.default');
        $done = 0;
        $failed = 0;

        Media::whereNull('content_hash')->chunkById(50, function ($chunk) use ($disk, &$done, &$failed) {
            foreach ($chunk as $media) {
                try {
                    if (! $media->file_path || ! Storage::disk($disk)->exists($media->file_path)) {
                        $failed++;
                        continue;
                    }
                    $media->content_hash = hash('sha256', Storage::disk($disk)->get($media->file_path));
                    $media->saveQuietly(); // pas d'événements (évite une réindexation Scout)
                    $done++;
                } catch (\Throwable $e) {
                    $failed++;
                    $this->warn("Échec {$media->id} : {$e->getMessage()}");
                }
            }
        });

        $this->info("content_hash calculé : {$done} — échecs : {$failed}");

        return self::SUCCESS;
    }
}
