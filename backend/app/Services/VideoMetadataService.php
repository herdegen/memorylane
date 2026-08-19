<?php

namespace App\Services;

use App\Models\Media;

class VideoMetadataService
{
    /** Conteneurs que les navigateurs savent lire nativement. */
    public const WEB_SAFE_MIME_TYPES = ['video/mp4', 'video/webm'];

    /** Codecs vidéo lisibles nativement dans ces conteneurs. */
    public const WEB_SAFE_VIDEO_CODECS = ['h264', 'vp8', 'vp9', 'av1'];

    /**
     * Détermine si une vidéo nécessite une version transcodée pour le web
     * (.mkv, .avi, HEVC… ne se lisent pas dans la plupart des navigateurs).
     * Retourne false si le codec est inconnu : impossible de juger, et le
     * transcodage échouerait probablement aussi.
     */
    public function needsWebVersion(Media $media): bool
    {
        if ($media->type !== 'video' || ! $media->video_codec) {
            return false;
        }

        return ! (in_array($media->mime_type, self::WEB_SAFE_MIME_TYPES, true)
            && in_array($media->video_codec, self::WEB_SAFE_VIDEO_CODECS, true));
    }

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
        $ffprobe = FfmpegFactory::ffprobe();

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
