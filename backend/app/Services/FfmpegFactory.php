<?php

namespace App\Services;

use FFMpeg\FFMpeg;
use FFMpeg\FFProbe;
use FFMpeg\Format\Video\X264;

/**
 * Point unique de création des instances FFmpeg/FFprobe et du format web
 * partagé (transcodage 1080p, clips) — les binaires viennent de config/media.php.
 */
class FfmpegFactory
{
    public static function ffmpeg(int $timeout = 3600): FFMpeg
    {
        return FFMpeg::create([
            'ffmpeg.binaries' => config('media.ffmpeg_binaries'),
            'ffprobe.binaries' => config('media.ffprobe_binaries'),
            'timeout' => $timeout,
            'ffmpeg.threads' => 4,
        ]);
    }

    public static function ffprobe(): FFProbe
    {
        return FFProbe::create([
            'ffprobe.binaries' => config('media.ffprobe_binaries'),
        ]);
    }

    /**
     * Format X264 commun aux sorties destinées au navigateur.
     * faststart : lecture avant téléchargement complet ; yuv420p : compat
     * navigateurs (les sources 10 bits type HEVC repassent en 8 bits).
     */
    public static function webX264(): X264
    {
        $format = new X264('aac', 'libx264');
        $format->setKiloBitrate(2500);
        $format->setAudioKiloBitrate(128);
        $format->setAdditionalParameters(['-movflags', '+faststart', '-preset', 'fast', '-pix_fmt', 'yuv420p']);

        return $format;
    }
}
