<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMediaConversions;
use App\Models\Media;
use App\Services\PerceptualHashService;
use App\Services\S3Service;
use App\Services\Vision\FaceEngine;
use App\Services\Vision\FaceReconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Remplace l'original de photos déjà importées par une version plus grande
 * (ex. export « web » d'un photographe → export haute définition), sans rien
 * perdre autour : albums, personnes, dates, commentaires restent ; les cadres
 * de visages sont en % de l'image, donc toujours en place.
 *
 * Appariement par nom de fichier, puis contrôles : même fichier RAW d'origine
 * (XMP crs:RawFileName) quand il est connu, mêmes proportions, image
 * ressemblante (dHash), version réellement plus grande. Le moindre doute →
 * photo sautée. L'ancien fichier et ses miniatures restent dans le stockage ;
 * chaque remplacement est consigné (storage/app/replace-originals.jsonl).
 *
 * Visages : nouvelle analyse en haute définition ; les noms validés par un
 * humain restent, les autres ne sont gardés que si la reconnaissance les
 * confirme, le reste est ré-identifié (FaceReconciler::rebuild recheck).
 */
class ReplaceOriginals extends Command
{
    protected $signature = 'media:replace-originals
        {dir : dossier des nouvelles versions (absolu, ou relatif à storage/app)}
        {--only=* : seulement ces noms de fichiers}
        {--dry-run : contrôler sans rien remplacer}
        {--max-dhash=10 : écart dHash maximal (sur 64 bits)}';

    protected $description = 'Remplace des photos par leur version haute définition, sans rien perdre';

    public function handle(S3Service $s3, PerceptualHashService $phash, FaceEngine $engine, FaceReconciler $reconciler): int
    {
        $dir = str_starts_with($this->argument('dir'), '/') ? $this->argument('dir') : storage_path('app/'.$this->argument('dir'));
        $files = array_values(array_filter(glob(rtrim($dir, '/').'/*') ?: [], fn ($f) => preg_match('/\.jpe?g$/i', $f)));
        if ($only = $this->option('only')) {
            $files = array_values(array_filter($files, fn ($f) => in_array(basename($f), $only, true)));
        }
        sort($files);
        $this->info(count($files).' fichier(s) à examiner'.($this->option('dry-run') ? ' (simulation)' : '').'.');

        $disk = Storage::disk($s3->getDisk());
        $visibility = in_array($s3->getDisk(), ['local', 'public']) ? 'public' : 'private';
        $counts = ['remplacée' => 0, 'prête' => 0, 'sautée' => 0];

        foreach ($files as $path) {
            $name = basename($path);
            $candidates = Media::where('original_name', $name)->where('type', 'photo')->get();
            if ($candidates->count() !== 1) {
                $this->skip($counts, $name, $candidates->isEmpty() ? 'aucune photo de ce nom' : 'plusieurs photos de ce nom');

                continue;
            }
            $media = $candidates->first();

            // Dimensions brutes du fichier, comme à l'import (MediaService).
            [$width, $height] = @getimagesize($path) ?: [0, 0];
            if ($width <= ($media->width ?? 0)) {
                $this->skip($counts, $name, "pas plus grande ({$width} px contre {$media->width} px)");

                continue;
            }
            if ($media->width && $media->height && abs($width / $height - $media->width / $media->height) > 0.01 * ($media->width / $media->height)) {
                $this->skip($counts, $name, 'proportions différentes (recadrage ?)');

                continue;
            }
            $old = $disk->get($media->file_path) ?? '';
            $rawOld = $this->rawName($old);
            $rawNew = $this->rawName((string) file_get_contents($path));
            if ($rawOld && $rawNew && $rawOld !== $rawNew) {
                $this->skip($counts, $name, "autre prise de vue ({$rawNew} au lieu de {$rawOld})");

                continue;
            }
            $hashNew = $phash->fromFile($path);
            $hashOld = $media->perceptual_hash ?: $phash->fromFile($old);
            $gap = ($hashNew && $hashOld) ? $this->hamming($hashNew, $hashOld) : null;
            if ($gap === null || $gap > (int) $this->option('max-dhash')) {
                $this->skip($counts, $name, 'image trop différente (dHash '.($gap ?? '?').')');

                continue;
            }

            $summary = "{$name} : {$media->width}×{$media->height} → {$width}×{$height}, dHash {$gap}".($rawOld ? ", {$rawOld}" : '');
            if ($this->option('dry-run')) {
                $counts['prête']++;
                $this->line("  ✓ {$summary}");

                continue;
            }

            try {
                $newPath = $s3->generateFilePath('photo', 'jpg');
                $s3->putFile($path, $newPath, $visibility);
                $before = $media->only(['file_path', 'size', 'width', 'height', 'content_hash', 'perceptual_hash'])
                    + ['conversions' => $media->conversions()->pluck('file_path', 'conversion_name')->all()];
                $media->forceFill([
                    'file_path' => $newPath,
                    'size' => filesize($path),
                    'width' => $width,
                    'height' => $height,
                    'content_hash' => hash_file('sha256', $path),
                    'perceptual_hash' => $hashNew,
                ])->save();
                file_put_contents(storage_path('app/replace-originals.jsonl'), json_encode([
                    'at' => now()->toIso8601String(), 'media_id' => $media->id, 'name' => $name, 'before' => $before, 'after' => $newPath,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);

                GenerateMediaConversions::dispatchSync($media->fresh(), false);

                $faces = '';
                if (FaceEngine::enabled() && ($detections = $engine->analyze($media->fresh())) !== null) {
                    $s = $reconciler->rebuild($media->fresh(), $detections, recheck: true);
                    $faces = " ; visages : {$s['updated']} retrouvés, {$s['added']} nouveaux, {$s['rechecked']} noms non confirmés retirés, {$s['deleted']} cadres effacés";
                }
                $counts['remplacée']++;
                $this->line("  ✓ {$summary}{$faces}");
            } catch (\Throwable $e) {
                $this->skip($counts, $name, 'erreur : '.$e->getMessage());
            }
        }

        $this->info(collect($counts)->filter()->map(fn ($n, $k) => "{$n} {$k}(s)")->implode(', ') ?: 'Rien à faire.');

        return self::SUCCESS;
    }

    private function skip(array &$counts, string $name, string $why): void
    {
        $counts['sautée']++;
        $this->warn("  ✗ {$name} : {$why}");
    }

    /** Fichier RAW d'origine noté par Lightroom dans le XMP, s'il y est. */
    private function rawName(string $bytes): ?string
    {
        return preg_match('/crs:RawFileName="([^"]+)"/', $bytes, $m) ? $m[1] : null;
    }

    private function hamming(string $a, string $b): int
    {
        $bits = 0;
        foreach (str_split($a, 2) as $i => $byte) {
            $bits += substr_count(decbin(hexdec($byte) ^ hexdec(substr($b, $i * 2, 2))), '1');
        }

        return $bits;
    }
}
