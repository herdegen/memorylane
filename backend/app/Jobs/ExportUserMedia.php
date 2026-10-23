<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\User;
use App\Notifications\MediaExportReady;
use App\Services\S3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;

/**
 * « Télécharger mes photos » : les originaux du compte, rangés par année,
 * en archives ZIP d'environ 1 Go (sans recompression), déposées sur le
 * stockage puis envoyées par mail (liens 7 jours, effacés au bout de 8).
 * File longue (connexion redis-long) : quelques Go à rapatrier.
 */
class ExportUserMedia implements ShouldQueue
{
    use Localizable, Queueable;

    public int $timeout = 4 * 3600;

    public int $tries = 1;

    public const PART_BYTES = 1024 * 1024 * 1024;

    public const LINK_MINUTES = 7 * 24 * 60;

    public function __construct(public User $user)
    {
        $this->onConnection('redis-long')->onQueue('video');
    }

    public function handle(S3Service $s3): void
    {
        $token = Str::lower(Str::random(16));
        $workdir = storage_path("app/exports/{$token}");
        @mkdir($workdir, 0775, true);
        $disk = Storage::disk($s3->getDisk());

        // En-tête de l'index et dossier des photos sans date, dans la langue du membre.
        [$header, $undated] = $this->withLocale($this->user->preferredLocale(), fn () => [
            [__('fichier'), __('date'), __('titre'), __('albums'), __('personnes')],
            __('sans-date'),
        ]);

        $parts = [];
        $paths = [];
        $count = 0;
        $zip = null;
        $zipPath = null;
        $partBytes = 0;
        $index = [];
        $used = [];

        $closePart = function () use (&$zip, &$zipPath, &$index, &$parts, &$paths, &$partBytes, &$used, $workdir, $token, $s3, $header) {
            if (! $zip) {
                return;
            }
            $csv = fopen('php://temp', 'r+');
            fputcsv($csv, $header);
            foreach ($index as $row) {
                fputcsv($csv, $row);
            }
            rewind($csv);
            $zip->addFromString('index.csv', "\xEF\xBB\xBF".stream_get_contents($csv));
            $zip->close();

            $n = count($parts) + 1;
            $name = "memorylane-photos-{$n}.zip";
            $key = "exports/{$this->user->id}/{$token}/{$name}";
            $s3->putFile($zipPath, $key);
            $parts[] = ['name' => $name, 'url' => $s3->getDownloadUrl($key, $name, self::LINK_MINUTES), 'size' => filesize($zipPath)];
            $paths[] = $key;
            @unlink($zipPath);
            foreach (glob("{$workdir}/f_*") as $f) {
                @unlink($f);
            }
            $zip = null;
            $partBytes = 0;
            $index = [];
            $used = [];
        };

        Media::where('user_id', $this->user->id)
            ->with(['albums:id,name', 'people:id,name'])
            ->orderByRaw('taken_at asc nulls last')->orderBy('id')
            ->chunk(100, function ($chunk) use (&$zip, &$zipPath, &$partBytes, &$index, &$used, &$count, $workdir, $disk, $closePart, &$parts, $undated) {
                foreach ($chunk as $media) {
                    if (! $media->file_path || ! $disk->exists($media->file_path)) {
                        continue;
                    }
                    if ($zip && $partBytes >= self::PART_BYTES) {
                        $closePart();
                    }
                    if (! $zip) {
                        $zipPath = "{$workdir}/part-".(count($parts) + 1).'.zip';
                        $zip = new \ZipArchive();
                        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                    }

                    $local = "{$workdir}/f_{$media->id}";
                    $in = $disk->readStream($media->file_path);
                    $out = fopen($local, 'w');
                    stream_copy_to_stream($in, $out);
                    fclose($out);
                    is_resource($in) && fclose($in);

                    // Rangé par année, préfixé par la date (tri naturel), sans doublon de nom.
                    $year = $media->taken_at?->format('Y') ?? $undated;
                    $base = ($media->taken_at ? $media->taken_at->format('Y-m-d').'_' : '').Str::of($media->original_name ?: $media->id)->replace(['/', '\\'], '-');
                    $entry = "{$year}/{$base}";
                    for ($i = 2; isset($used[$entry]); $i++) {
                        $entry = "{$year}/".pathinfo($base, PATHINFO_FILENAME)." ({$i}).".pathinfo($base, PATHINFO_EXTENSION);
                    }
                    $used[$entry] = true;
                    $zip->addFile($local, $entry);
                    $zip->setCompressionName($entry, \ZipArchive::CM_STORE); // photos déjà compressées

                    $index[] = [
                        $entry,
                        $media->taken_at?->format('Y-m-d H:i') ?? '',
                        (string) $media->title,
                        $media->albums->pluck('name')->implode(' | '),
                        $media->people->pluck('name')->implode(' | '),
                    ];
                    $partBytes += filesize($local);
                    $count++;
                }
            });
        $closePart();
        @rmdir($workdir);

        if ($parts === []) {
            return;
        }
        $this->user->notify(new MediaExportReady($parts, $count));
        DeleteExportFiles::dispatch($paths)->delay(now()->addDays(8));
    }
}
