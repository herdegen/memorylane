<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\PerceptualHashService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Rétro-remplit perceptual_hash (dHash) pour les PHOTOS importées avant l'ajout
 * de l'empreinte perceptuelle, afin que la détection de quasi-doublons (#42)
 * couvre l'existant. À lancer une fois.
 */
class BackfillPerceptualHashes extends Command
{
    protected $signature = 'media:backfill-perceptual-hashes';

    protected $description = 'Calcule perceptual_hash (dHash) pour les photos sans empreinte perceptuelle';

    public function handle(PerceptualHashService $hasher): int
    {
        $disk = config('filesystems.default');
        $done = 0;
        $failed = 0;

        Media::query()
            ->where('type', 'photo')
            ->whereNull('perceptual_hash')
            ->chunkById(50, function ($chunk) use ($disk, $hasher, &$done, &$failed) {
                foreach ($chunk as $media) {
                    try {
                        if (! $media->file_path || ! Storage::disk($disk)->exists($media->file_path)) {
                            $failed++;
                            continue;
                        }

                        $hash = $hasher->fromFile(Storage::disk($disk)->get($media->file_path));

                        if (! $hash) {
                            $failed++;
                            continue;
                        }

                        // Mise à jour ciblée + silencieuse (pas de réindexation Scout).
                        Media::whereKey($media->id)->update(['perceptual_hash' => $hash]);
                        $done++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->warn("Échec {$media->id} : {$e->getMessage()}");
                    }
                }
            });

        $this->info("perceptual_hash calculé : {$done} — échecs : {$failed}");

        return self::SUCCESS;
    }
}
