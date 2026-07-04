<?php

namespace App\Jobs;

use App\Jobs\Concerns\DownloadsMediaToTemp;
use App\Models\Media;
use App\Services\S3Service;
use FFMpeg\FFProbe;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractVideoMetadata implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels, DownloadsMediaToTemp;

    public $tries = 3;

    public $timeout = 300;

    public function __construct(
        public Media $media
    ) {}

    public function handle(S3Service $s3Service): void
    {
        if ($this->media->type !== 'video') {
            return;
        }

        try {
            Log::info('ExtractVideoMetadata: Starting', [
                'media_id' => $this->media->id,
                'file_path' => $this->media->file_path,
            ]);

            $tempPath = $this->downloadFileToTemp($s3Service);

            if (! $tempPath) {
                Log::warning('ExtractVideoMetadata: Failed to download file from S3', [
                    'media_id' => $this->media->id,
                ]);
                return;
            }

            try {
                $ffprobe = FFProbe::create([
                    'ffprobe.binaries' => env('FFPROBE_BINARIES', '/usr/bin/ffprobe'),
                ]);

                $streams = $ffprobe->streams($tempPath);
                $format  = $ffprobe->format($tempPath);

                $videoStream = $streams->videos()->first();
                $audioStream = $streams->audios()->first();

                $this->media->update([
                    'duration'    => $format->get('duration') ? (int) round((float) $format->get('duration')) : null,
                    'width'       => $videoStream ? (int) $videoStream->get('width') : null,
                    'height'      => $videoStream ? (int) $videoStream->get('height') : null,
                    'video_codec' => $videoStream ? $videoStream->get('codec_name') : null,
                    'audio_codec' => $audioStream ? $audioStream->get('codec_name') : null,
                    'fps'         => $videoStream ? $this->parseFps($videoStream->get('r_frame_rate')) : null,
                    'bitrate'     => $format->get('bit_rate') ? (int) round((float) $format->get('bit_rate') / 1000) : null,
                ]);

                Log::info('ExtractVideoMetadata: Completed', [
                    'media_id'    => $this->media->id,
                    'duration'    => $this->media->duration,
                    'video_codec' => $this->media->video_codec,
                    'fps'         => $this->media->fps,
                    'bitrate'     => $this->media->bitrate,
                ]);
            } finally {
                @unlink($tempPath);
            }
        } catch (\Exception $e) {
            Log::error('ExtractVideoMetadata: Failed', [
                'media_id' => $this->media->id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Parse a frame rate string like "30000/1001" or "25" into a float.
     */
    protected function parseFps(?string $rFrameRate): ?float
    {
        if (! $rFrameRate) {
            return null;
        }

        if (str_contains($rFrameRate, '/')) {
            [$num, $den] = explode('/', $rFrameRate);
            $den = (int) $den;
            return $den > 0 ? round((int) $num / $den, 3) : null;
        }

        $fps = (float) $rFrameRate;
        return $fps > 0 ? round($fps, 3) : null;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtractVideoMetadata: Job failed permanently', [
            'media_id' => $this->media->id,
            'error'    => $exception->getMessage(),
        ]);
    }
}
