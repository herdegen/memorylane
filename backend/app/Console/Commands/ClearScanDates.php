<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\AlbumDating;
use App\Services\ExifExtractor;
use Illuminate\Console\Command;

/**
 * Photos papier numérisées : retire la date du scan prise pour la date de la
 * photo. Seulement quand la date actuelle est encore celle du scan (EXIF) —
 * une date corrigée à la main ou héritée d'un album est gardée. Les photos
 * redevenues sans date prennent ensuite la date de leur album s'il en a une.
 * Chaque retrait est consigné (storage/app/clear-scan-dates.jsonl).
 */
class ClearScanDates extends Command
{
    protected $signature = 'media:clear-scan-dates {--dry-run : lister sans rien modifier}';

    protected $description = 'Retire la date du scan des photos papier numérisées';

    public function handle(AlbumDating $dating): int
    {
        $dry = (bool) $this->option('dry-run');
        $cleared = [];

        Media::where('type', 'photo')
            ->whereNotNull('taken_at')
            ->whereNull('taken_at_album_id')
            ->with('metadata', 'albums:id,name')
            ->whereHas('metadata')
            ->chunkById(500, function ($chunk) use (&$cleared, $dry) {
                foreach ($chunk as $media) {
                    $meta = $media->metadata;
                    $exif = is_array($meta->exif_data) ? $meta->exif_data : [];
                    if (! ExifExtractor::isScan($exif, $meta->camera_make, $meta->camera_model)) {
                        continue;
                    }
                    $scanDay = ExifExtractor::storedExifDay($exif);
                    if ($scanDay === null || $scanDay !== $media->taken_at->toDateString()) {
                        continue; // date corrigée à la main : on la garde
                    }

                    $album = $media->albums->first()?->name ?? '—';
                    $this->line(($dry ? '[aperçu] ' : '')."{$media->original_name} : {$scanDay} ({$album})");
                    if (! $dry) {
                        file_put_contents(storage_path('app/clear-scan-dates.jsonl'), json_encode([
                            'at' => now()->toIso8601String(),
                            'media_id' => $media->id,
                            'taken_at' => $media->taken_at->toDateTimeString(),
                        ])."\n", FILE_APPEND);
                        $media->update(['taken_at' => null]);
                    }
                    $cleared[] = $media->id;
                }
            });

        $inherited = $dry ? 0 : $dating->syncMedia($cleared);
        $this->info(count($cleared).' photo(s) '.($dry ? 'à remettre' : 'remise(s)').' sans date'
            .($dry ? '.' : ", dont {$inherited} datée(s) par leur album."));

        return self::SUCCESS;
    }
}
