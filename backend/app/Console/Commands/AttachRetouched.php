<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMediaConversions;
use App\Models\Media;
use App\Services\MediaService;
use App\Services\PerceptualHashService;
use App\Services\S3Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Versions retouchées de vieilles photos (usure corrigée) : chaque fichier du
 * dossier est rattaché à la photo dont il est la retouche. La retouche devient
 * l'image affichée ; le scan d'origine est gardé (fichier + aperçu) et chacun
 * peut basculer de l'un à l'autre. Rien ne se perd autour : personnes,
 * visages (cadres en % de l'image), date, albums, mémos restent.
 *
 * Appariement : par ressemblance (dHash), confirmée par les proportions ; un
 * nom de fichier qui reprend celui de la photo (« lettre-1940-retouche.jpg »
 * pour « lettre-1940.jpg ») autorise un écart un peu plus grand. Le moindre
 * doute (aucune photo assez proche, deux photos aussi proches) → fichier
 * sauté. --dry-run montre les correspondances sans rien changer.
 * --restore=ID remet le scan d'origine comme image de la photo.
 */
class AttachRetouched extends Command
{
    protected $signature = 'media:attach-retouched
        {dir? : dossier des versions retouchées (absolu, ou relatif à storage/app)}
        {--only=* : seulement ces noms de fichiers}
        {--dry-run : montrer les correspondances sans rien changer}
        {--max-dhash=12 : écart dHash maximal (sur 64 bits)}
        {--restore=* : remettre le scan d\'origine de ces photos (identifiants)}';

    protected $description = 'Rattache des versions retouchées à leurs photos, en gardant le scan d\'origine';

    /** Écart minimal avec la deuxième photo la plus proche : sinon, c'est ambigu. */
    private const MARGIN = 3;

    /** Mots de fin de nom qui désignent une retouche. */
    private const SUFFIXES = ['retouche', 'retouchee', 'retouched', 'restaure', 'restauree', 'restored', 'restore', 'repare', 'reparee', 'ia', 'ai', 'edit', 'edited', 'final', 'hd'];

    public function handle(S3Service $s3, PerceptualHashService $phash): int
    {
        if ($ids = $this->option('restore')) {
            return $this->restore($s3, $phash, $ids);
        }
        if (! $this->argument('dir')) {
            $this->error('Indiquez le dossier des versions retouchées (ou --restore=ID).');

            return self::FAILURE;
        }

        $dir = str_starts_with($this->argument('dir'), '/') ? $this->argument('dir') : storage_path('app/'.$this->argument('dir'));
        $files = array_values(array_filter(glob(rtrim($dir, '/').'/*') ?: [], fn ($f) => preg_match('/\.(jpe?g|png|webp)$/i', $f)));
        if ($only = $this->option('only')) {
            $files = array_values(array_filter($files, fn ($f) => in_array(basename($f), $only, true)));
        }
        sort($files);
        $dry = (bool) $this->option('dry-run');
        $this->info(count($files).' fichier(s) à examiner'.($dry ? ' (simulation)' : '').'.');

        $pool = Media::where('type', 'photo')->whereNotNull('perceptual_hash')
            ->get(['id', 'original_name', 'title', 'width', 'height', 'perceptual_hash', 'file_path', 'original_scan_path']);
        $max = (int) $this->option('max-dhash');
        $counts = ['rattachée' => 0, 'prête' => 0, 'sautée' => 0];

        foreach ($files as $path) {
            $name = basename($path);
            [$width, $height] = @getimagesize($path) ?: [0, 0];
            $hash = $phash->fromFile($path);
            if (! $width || ! $hash) {
                $this->skip($counts, $name, 'image illisible');

                continue;
            }

            $stem = $this->stem($name);
            $ranked = $pool->map(fn (Media $m) => ['media' => $m, 'gap' => $this->hamming($hash, $m->perceptual_hash), 'named' => $stem !== '' && $this->stem((string) $m->original_name) === $stem])
                ->filter(fn ($c) => $this->sameShape($width, $height, $c['media']))
                ->sortBy('gap')->values();

            $named = $ranked->where('named', true)->values();
            if ($named->count() === 1 && $named[0]['gap'] <= $max + 8) {
                $pick = $named[0];
            } else {
                $best = $ranked->first();
                $second = $ranked->get(1);
                if (! $best || $best['gap'] > $max) {
                    $this->skip($counts, $name, 'aucune photo assez ressemblante'.($best ? " (au mieux dHash {$best['gap']} : {$this->label($best['media'])})" : ''));

                    continue;
                }
                if ($second && $second['gap'] - $best['gap'] < self::MARGIN) {
                    $this->skip($counts, $name, "ambigu : {$this->label($best['media'])} (dHash {$best['gap']}) ou {$this->label($second['media'])} (dHash {$second['gap']})");

                    continue;
                }
                $pick = $best;
            }

            $media = $pick['media'];
            $again = $media->original_scan_path ? ', nouvelle retouche (scan d\'origine conservé)' : '';
            $summary = "{$name} → {$this->label($media)} : dHash {$pick['gap']}".($pick['named'] ? ', même nom' : '').", {$media->width}×{$media->height} → {$width}×{$height}{$again}";
            if ($dry) {
                $counts['prête']++;
                $this->line("  ✓ {$summary}");

                continue;
            }

            try {
                $this->attach($s3, $media->fresh(), $path, $width, $height, $hash);
                $counts['rattachée']++;
                $this->line("  ✓ {$summary}");
            } catch (\Throwable $e) {
                $this->skip($counts, $name, 'erreur : '.$e->getMessage());
            }
        }

        $this->info(collect($counts)->filter()->map(fn ($n, $k) => "{$n} {$k}(s)")->implode(', ') ?: 'Rien à faire.');

        return self::SUCCESS;
    }

    private function attach(S3Service $s3, Media $media, string $path, int $width, int $height, string $hash): void
    {
        $disk = Storage::disk($s3->getDisk());
        $visibility = in_array($s3->getDisk(), ['local', 'public']) ? 'public' : 'private';
        $mime = mime_content_type($path) ?: 'image/jpeg';
        $newPath = $s3->generateFilePath('photo', MediaService::extensionForMime($mime));
        $s3->putFile($path, $newPath, $visibility);

        $fill = [
            'file_path' => $newPath,
            'mime_type' => $mime,
            'size' => filesize($path),
            'width' => $width,
            'height' => $height,
            'content_hash' => hash_file('sha256', $path),
            'perceptual_hash' => $hash,
            'retouched_at' => now(),
        ];
        if (! $media->original_scan_path) {
            // Première retouche : le fichier actuel devient le scan d'origine, et
            // son grand aperçu est copié à part (les miniatures vont être refaites,
            // les anciennes effacées).
            $fill['original_scan_path'] = $media->file_path;
            $preview = $media->conversions()->whereIn('conversion_name', ['large', 'medium'])
                ->orderByRaw("conversion_name = 'large' desc")->value('file_path');
            if ($preview) {
                $copy = preg_replace('/(\.[a-z0-9]+)$/i', '_scan$1', $preview) ?: $preview.'_scan';
                $disk->copy($preview, $copy);
                $fill['original_scan_preview_path'] = $copy;
            }
        }
        $before = $media->only(['file_path', 'width', 'height', 'perceptual_hash', 'original_scan_path']);
        $media->forceFill($fill)->save();
        file_put_contents(storage_path('app/attach-retouched.jsonl'), json_encode([
            'at' => now()->toIso8601String(), 'media_id' => $media->id, 'file' => basename($path), 'before' => $before, 'after' => $newPath,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);

        // Miniatures refaites depuis la retouche ; visages gardés tels quels
        // (même cadrage : les cadres en % restent en place, les noms aussi).
        GenerateMediaConversions::dispatchSync($media->fresh(), false);
    }

    /** --restore : le scan d'origine redevient l'image de la photo. */
    private function restore(S3Service $s3, PerceptualHashService $phash, array $ids): int
    {
        $disk = Storage::disk($s3->getDisk());
        foreach (Media::whereIn('id', $ids)->get() as $media) {
            if (! $media->original_scan_path) {
                $this->warn("  ✗ {$this->label($media)} : pas de scan d'origine gardé");

                continue;
            }
            // Avec son extension : le calcul de ressemblance en a besoin.
            $tmp = sys_get_temp_dir().'/scan-'.uniqid().'.'.(pathinfo($media->original_scan_path, PATHINFO_EXTENSION) ?: 'jpg');
            file_put_contents($tmp, $disk->get($media->original_scan_path));
            [$width, $height] = @getimagesize($tmp) ?: [$media->width, $media->height];
            $retouched = $media->file_path;
            $media->forceFill([
                'file_path' => $media->original_scan_path,
                'mime_type' => mime_content_type($tmp) ?: $media->mime_type,
                'size' => filesize($tmp),
                'width' => $width,
                'height' => $height,
                'content_hash' => hash_file('sha256', $tmp),
                'perceptual_hash' => $phash->fromFile($tmp) ?: $media->perceptual_hash,
                'original_scan_path' => null,
                'original_scan_preview_path' => null,
                'retouched_at' => null,
            ])->save();
            @unlink($tmp);
            GenerateMediaConversions::dispatchSync($media->fresh(), false);
            file_put_contents(storage_path('app/attach-retouched.jsonl'), json_encode([
                'at' => now()->toIso8601String(), 'media_id' => $media->id, 'restored' => true, 'retouched_file_kept' => $retouched,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
            $this->line("  ✓ {$this->label($media)} : scan d'origine remis (la retouche reste dans le stockage)");
        }

        return self::SUCCESS;
    }

    private function sameShape(int $width, int $height, Media $m): bool
    {
        if (! $m->width || ! $m->height || ! $height) {
            return true;
        }
        $a = $width / $height;
        $b = $m->width / $m->height;

        return abs($a - $b) <= 0.03 * $b;
    }

    /** Nom sans extension, accents, séparateurs ni mot de retouche final. */
    private function stem(string $name): string
    {
        $s = strtolower(\Illuminate\Support\Str::ascii(pathinfo($name, PATHINFO_FILENAME)));
        $words = preg_split('/[^a-z0-9]+/', $s, -1, PREG_SPLIT_NO_EMPTY);
        while ($words && in_array(end($words), self::SUFFIXES, true)) {
            array_pop($words);
        }

        return implode('-', $words);
    }

    private function label(Media $m): string
    {
        return ($m->title ?: $m->original_name).' ['.substr($m->id, -6).']';
    }

    private function skip(array &$counts, string $name, string $why): void
    {
        $counts['sautée']++;
        $this->warn("  ✗ {$name} : {$why}");
    }

    private function hamming(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return 64;
        }
        $bits = 0;
        foreach (str_split($a, 2) as $i => $byte) {
            $bits += substr_count(decbin(hexdec($byte) ^ hexdec(substr($b, $i * 2, 2))), '1');
        }

        return $bits;
    }
}
