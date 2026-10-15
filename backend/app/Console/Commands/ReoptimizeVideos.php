<?php

namespace App\Console\Commands;

use App\Jobs\GenerateMediaConversions;
use App\Models\Media;
use App\Services\S3Service;
use App\Services\VideoOptimizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Réoptimise des vidéos déjà importées (réglages de VideoOptimizer revus) :
 * le fichier n'est remplacé que s'il devient plus léger ; dates, personnes,
 * albums, visages restent. L'ancien fichier est supprimé (c'était déjà une
 * version optimisée : l'original est ailleurs, ex. Google Drive).
 */
class ReoptimizeVideos extends Command
{
    protected $signature = 'media:reoptimize
        {media* : id(s) des vidéos}
        {--dry-run : mesurer sans remplacer}';

    protected $description = 'Réencode des vidéos déjà importées si cela les allège';

    public function handle(S3Service $s3, VideoOptimizer $optimizer): int
    {
        $disk = Storage::disk($s3->getDisk());
        $visibility = in_array($s3->getDisk(), ['local', 'public'], true) ? 'public' : 'private';

        foreach ((array) $this->argument('media') as $id) {
            $media = Str::isUuid($id) ? Media::find($id) : null;
            if (! $media || $media->type !== 'video') {
                $this->warn("  ✗ {$id} : vidéo introuvable");

                continue;
            }
            $source = sys_get_temp_dir().'/reopt_src_'.Str::uuid().'.'.pathinfo($media->file_path, PATHINFO_EXTENSION);
            $output = sys_get_temp_dir().'/reopt_out_'.Str::uuid().'.mp4';
            try {
                $in = $disk->readStream($media->file_path);
                $out = fopen($source, 'w');
                stream_copy_to_stream($in, $out);
                fclose($out);

                $optimizer->optimize($source, $output);
                $before = filesize($source);
                $after = filesize($output);
                $label = "{$media->original_name} : ".round($before / 1048576).' Mo → '.round($after / 1048576).' Mo';
                if ($after >= $before) {
                    $this->line("  = {$label} (gardée telle quelle)");

                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line("  ✓ {$label} (simulation)");

                    continue;
                }

                $probe = $optimizer->probe($output);
                $newPath = $s3->generateFilePath('video', 'mp4');
                $s3->putFile($output, $newPath, $visibility);
                $oldPath = $media->file_path;
                $media->forceFill([
                    'file_path' => $newPath, 'mime_type' => 'video/mp4', 'size' => $after,
                    'width' => $probe['width'], 'height' => $probe['height'], 'content_hash' => hash_file('sha256', $output),
                ])->save();
                $disk->delete($oldPath);
                GenerateMediaConversions::dispatch($media->fresh());
                $this->line("  ✓ {$label}");
            } finally {
                @unlink($source);
                @unlink($output);
            }
        }

        return self::SUCCESS;
    }
}
