<?php

namespace App\Jobs;

use App\Jobs\Concerns\DownloadsMediaToTemp;
use App\Models\Media;
use App\Models\MediaConversion;
use App\Services\S3Service;
use FFMpeg\Coordinate\Dimension;
use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Filters\Video\ResizeFilter;
use FFMpeg\Format\Video\X264;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Transcode une vidéo en MP4 H.264/AAC lisible par tous les navigateurs.
 *
 * Dispatché par GenerateMediaConversions quand le format source n'est pas
 * lisible nativement (.mkv, .avi, HEVC…). L'original est conservé ; la
 * version web est enregistrée comme conversion « web » et servie en priorité
 * par le lecteur.
 */
class TranscodeVideoForWeb implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, DownloadsMediaToTemp;

    public $tries = 2;

    public $timeout = 3600;

    public function __construct(
        public Media $media
    ) {}

    public function handle(S3Service $s3Service): void
    {
        if ($this->media->type !== 'video') {
            return;
        }

        Log::info('TranscodeVideoForWeb: Starting', ['media_id' => $this->media->id]);

        $tempPath = $this->downloadFileToTemp($s3Service);
        if (! $tempPath) {
            Log::warning('TranscodeVideoForWeb: Failed to download file from S3', [
                'media_id' => $this->media->id,
            ]);
            return;
        }

        $outputPath = sys_get_temp_dir() . '/video_web_' . uniqid() . '.mp4';

        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries'  => env('FFMPEG_BINARIES', '/usr/bin/ffmpeg'),
                'ffprobe.binaries' => env('FFPROBE_BINARIES', '/usr/bin/ffprobe'),
                'timeout'          => $this->timeout,
                'ffmpeg.threads'   => 4,
            ]);

            $video = $ffmpeg->open($tempPath);

            // Limite à 1080p (inset = ratio préservé) pour un poids raisonnable
            if (($this->media->width ?? 0) > 1920 || ($this->media->height ?? 0) > 1080) {
                $video->filters()
                    ->resize(new Dimension(1920, 1080), ResizeFilter::RESIZEMODE_INSET)
                    ->synchronize();
            }

            $format = new X264('aac', 'libx264');
            $format->setKiloBitrate(2500);
            $format->setAudioKiloBitrate(128);
            // faststart : lecture avant téléchargement complet ; yuv420p : compat
            // navigateurs (les sources 10 bits type HEVC repassent en 8 bits)
            $format->setAdditionalParameters(['-movflags', '+faststart', '-preset', 'fast', '-pix_fmt', 'yuv420p']);

            $video->save($format, $outputPath);

            // Dimensions réelles de la sortie
            $ffprobe = FFProbe::create([
                'ffprobe.binaries' => env('FFPROBE_BINARIES', '/usr/bin/ffprobe'),
            ]);
            $stream = $ffprobe->streams($outputPath)->videos()->first();

            $conversionPath = $this->uploadToS3($s3Service, $outputPath);

            MediaConversion::updateOrCreate(
                [
                    'media_id'        => $this->media->id,
                    'conversion_name' => 'web',
                ],
                [
                    'file_path' => $conversionPath,
                    'width'     => $stream ? (int) $stream->get('width') : null,
                    'height'    => $stream ? (int) $stream->get('height') : null,
                    'size'      => filesize($outputPath),
                    'mime_type' => 'video/mp4',
                ]
            );

            Log::info('TranscodeVideoForWeb: Completed', [
                'media_id' => $this->media->id,
                'size'     => filesize($outputPath),
            ]);
        } catch (\Exception $e) {
            Log::error('TranscodeVideoForWeb: Failed', [
                'media_id' => $this->media->id,
                'error'    => $e->getMessage(),
            ]);
            throw $e;
        } finally {
            @unlink($tempPath);
            @unlink($outputPath);
        }
    }

    /**
     * Upload the transcoded file next to the original.
     */
    protected function uploadToS3(S3Service $s3Service, string $localPath): string
    {
        $directory = dirname($this->media->file_path);
        $filename = pathinfo($this->media->file_path, PATHINFO_FILENAME);
        $conversionPath = "{$directory}/{$filename}_web.mp4";

        $visibility = in_array($s3Service->getDisk(), ['local', 'public']) ? 'public' : 'private';
        $s3Service->putFile($localPath, $conversionPath, $visibility);

        return $conversionPath;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('TranscodeVideoForWeb: Job failed permanently', [
            'media_id' => $this->media->id,
            'error'    => $exception->getMessage(),
        ]);
    }
}
