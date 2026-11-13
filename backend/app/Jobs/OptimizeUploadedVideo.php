<?php

namespace App\Jobs;

use App\Models\Media;
use App\Services\MediaService;
use App\Services\S3Service;
use App\Services\VideoOptimizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Vidéo envoyée dans un vieux format (MPEG de caméscope ou de DVD, MTS, 3GP,
 * WMV, DV…) : réencodée en MP4 optimisé (VideoOptimizer, comme l'import
 * Drive), qui REMPLACE l'original (choix de l'utilisateur : l'original reste
 * sur son ordinateur). Puis la chaîne habituelle (métadonnées, miniatures,
 * visages). En cas d'échec, l'original est gardé et suit la chaîne habituelle
 * (une version web en est tirée, comme pour un AVI).
 */
class OptimizeUploadedVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 4 * 3600 + 600;

    public function __construct(public Media $media)
    {
        $this->onConnection('redis-long')->onQueue('video');
    }

    /** Tâche abandonnée (délai dépassé…) : plus « en conversion », la suite habituelle prend le relais. */
    public function failed(\Throwable $e): void
    {
        $this->media->fresh()?->markConverting(false);
        app(MediaService::class)->startProcessing($this->media->fresh());
    }

    public function handle(S3Service $s3, VideoOptimizer $optimizer, MediaService $mediaService): void
    {
        $media = $this->media->fresh();
        if (! $media || $media->type !== 'video') {
            return;
        }
        $disk = Storage::disk($s3->getDisk());
        $source = sys_get_temp_dir().'/upload_src_'.Str::uuid().'.'.(pathinfo($media->file_path, PATHINFO_EXTENSION) ?: 'bin');
        $output = sys_get_temp_dir().'/upload_opt_'.Str::uuid().'.mp4';

        try {
            $in = $disk->readStream($media->file_path);
            $out = fopen($source, 'w');
            stream_copy_to_stream($in, $out);
            fclose($out);
            if (is_resource($in)) {
                fclose($in);
            }

            $probe = $optimizer->probe($source);
            if ($optimizer->needsOptimization($probe)) {
                Log::info('OptimizeUploadedVideo: optimisation', ['media_id' => $media->id, 'codec' => $probe['codec'], 'height' => $probe['height']]);
                $optimizer->optimize($source, $output, $probe);
                if (! $optimizer->keepSource($probe, $source, $output)) {
                    $after = $optimizer->probe($output);
                    $visibility = in_array($s3->getDisk(), ['local', 'public'], true) ? 'public' : 'private';
                    $newPath = $s3->generateFilePath('video', 'mp4');
                    $s3->putFile($output, $newPath, $visibility);
                    $oldPath = $media->file_path;
                    $media->forceFill([
                        'file_path' => $newPath,
                        'mime_type' => 'video/mp4',
                        'original_name' => pathinfo($media->original_name, PATHINFO_FILENAME).'.mp4',
                        'size' => filesize($output),
                        'width' => $after['width'] ?: $media->width,
                        'height' => $after['height'] ?: $media->height,
                        'content_hash' => hash_file('sha256', $output),
                    ])->save();
                    $disk->delete($oldPath);
                    Log::info('OptimizeUploadedVideo: optimisée', ['media_id' => $media->id, 'before' => filesize($source), 'after' => filesize($output)]);
                }
            }
        } catch (\Throwable $e) {
            // L'original reste : il aura sa version web par la chaîne habituelle.
            Log::warning('OptimizeUploadedVideo: échec, original gardé', ['media_id' => $media->id, 'error' => $e->getMessage()]);
        } finally {
            @unlink($source);
            @unlink($output);
        }

        $media = $media->fresh();
        $media->markConverting(false);
        $mediaService->startProcessing($media);
    }
}
