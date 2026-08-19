<?php

/*
 * Réglages médias applicatifs. Toujours lire ces valeurs via config() :
 * un env() hors des fichiers de config est silencieusement ignoré dès que
 * la config est cachée (php artisan optimize).
 */
return [

    // Plafond de l'upload direct multipart navigateur -> S3 (octets)
    'upload_max_bytes' => (int) env('UPLOAD_MAX_BYTES', 20 * 1024 * 1024 * 1024),

    // Binaires FFmpeg (conversions, clips, métadonnées vidéo)
    'ffmpeg_binaries' => env('FFMPEG_BINARIES', '/usr/bin/ffmpeg'),
    'ffprobe_binaries' => env('FFPROBE_BINARIES', '/usr/bin/ffprobe'),

];
