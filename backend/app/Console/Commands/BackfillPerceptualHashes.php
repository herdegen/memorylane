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
 *
 * On hashe une PETITE conversion (« small », 400px, ratio conservé) et jamais
 * l'original : le dHash réduit de toute façon en 9×8, mais décoder des
 * originaux plein format (+ auto-rotation EXIF) en boucle épuise la mémoire.
 * Cohérent avec l'ingestion (GenerateMediaConversions hashe aussi « small »).
 */
class BackfillPerceptualHashes extends Command
{
    protected $signature = 'media:backfill-perceptual-hashes';

    protected $description = 'Calcule perceptual_hash (dHash) pour les photos sans empreinte perceptuelle';

    /** Conversions utilisables pour le hash, par ordre de préférence (ratio conservé). */
    private const HASH_SOURCES = ['small', 'medium', 'large'];

    public function handle(PerceptualHashService $hasher): int
    {
        $disk = config('filesystems.default');
        $done = 0;
        $failed = 0;
        $skipped = 0;

        Media::query()
            ->where('type', 'photo')
            ->whereNull('perceptual_hash')
            ->with('conversions:id,media_id,conversion_name,file_path')
            ->chunkById(50, function ($chunk) use ($disk, $hasher, &$done, &$failed, &$skipped) {
                foreach ($chunk as $media) {
                    try {
                        $path = $this->hashSourcePath($media);

                        if (! $path || ! Storage::disk($disk)->exists($path)) {
                            // Pas de petite conversion exploitable : on saute
                            // plutôt que de décoder l'original (coûteux/risqué).
                            $skipped++;
                            continue;
                        }

                        $hash = $hasher->fromFile(Storage::disk($disk)->get($path));

                        if (! $hash) {
                            $failed++;
                            continue;
                        }

                        // Mise à jour ciblée (pas de réindexation Scout).
                        Media::whereKey($media->id)->update(['perceptual_hash' => $hash]);
                        $done++;
                    } catch (\Throwable $e) {
                        $failed++;
                        $this->warn("Échec {$media->id} : {$e->getMessage()}");
                    }
                }

                // Libère la mémoire accumulée par GD/Intervention entre les lots.
                gc_collect_cycles();
            });

        $this->info("perceptual_hash calculé : {$done} — sautés (sans conversion) : {$skipped} — échecs : {$failed}");

        return self::SUCCESS;
    }

    /** file_path de la conversion préférée pour le hash, ou null. */
    private function hashSourcePath(Media $media): ?string
    {
        foreach (self::HASH_SOURCES as $name) {
            $conversion = $media->conversions->firstWhere('conversion_name', $name);
            if ($conversion && $conversion->file_path) {
                return $conversion->file_path;
            }
        }

        return null;
    }
}
