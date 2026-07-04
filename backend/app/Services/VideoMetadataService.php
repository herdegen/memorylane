<?php

namespace App\Services;

use App\Models\Media;
use FFMpeg\FFProbe;

class VideoMetadataService
{
    /**
     * Extract technical metadata (duration, codecs, fps, bitrate, dimensions)
     * from a local video file and persist it on the media record.
     *
     * @param Media $media
     * @param string $localPath Path to the video file on local disk
     * @return void
     */
    public function extractFromFile(Media $media, string $localPath): void
    {
        $ffprobe = FFProbe::create([
            'ffprobe.binaries' => env('FFPROBE_BINARIES', '/usr/bin/ffprobe'),
        ]);

        $streams = $ffprobe->streams($localPath);
        $format  = $ffprobe->format($localPath);

        $videoStream = $streams->videos()->first();
        $audioStream = $streams->audios()->first();

        $media->update([
            'duration'    => $format->get('duration') ? (int) round((float) $format->get('duration')) : null,
            'width'       => $videoStream ? (int) $videoStream->get('width') : null,
            'height'      => $videoStream ? (int) $videoStream->get('height') : null,
            'video_codec' => $videoStream ? $videoStream->get('codec_name') : null,
            'audio_codec' => $audioStream ? $audioStream->get('codec_name') : null,
            'fps'         => $videoStream ? $this->parseFps($videoStream->get('r_frame_rate')) : null,
            'bitrate'     => $format->get('bit_rate') ? (int) round((float) $format->get('bit_rate') / 1000) : null,
        ]);
    }

    /**
     * Parse a frame rate string like "30000/1001" or "25" into a float.
     */
    public function parseFps(?string $rFrameRate): ?float
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
}
