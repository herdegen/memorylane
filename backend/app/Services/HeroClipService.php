<?php

namespace App\Services;

use App\Models\Media;
use App\Models\MediaConversion;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Extrait vidéo pour le fond d'un en-tête (album) : muet, en boucle, H.264
 * 720p lisible partout (même depuis une source HEVC), fondus d'entrée et de
 * sortie pour adoucir la boucle — quelques Mo au lieu de la vidéo entière.
 * Plus une image d'attente. Rangés en conversions « hero » et « hero_poster »
 * du média (supprimées avec lui).
 */
class HeroClipService
{
    public const CLIP = 'hero';

    public const POSTER = 'hero_poster';

    private const FADE = 0.8;

    /** 1280 px de large au plus, jamais agrandie. */
    private const SCALE = "scale='min(1280,iw)':-2";

    public function __construct(private S3Service $s3) {}

    public function generate(Media $video, float $start = 0, float $duration = 24): void
    {
        if ($video->type !== 'video') {
            throw new \InvalidArgumentException('Seule une vidéo peut servir de fond.');
        }
        if ($video->duration && $start >= $video->duration) {
            throw new \InvalidArgumentException("La vidéo ne dure que {$video->duration} s.");
        }

        $disk = Storage::disk($this->s3->getDisk());
        $visibility = in_array($this->s3->getDisk(), ['local', 'public'], true) ? 'public' : 'private';
        $dir = sys_get_temp_dir().'/hero_'.Str::uuid();
        File::makeDirectory($dir);

        try {
            $source = "{$dir}/source";
            $in = $disk->readStream($video->file_path);
            $out = fopen($source, 'w');
            stream_copy_to_stream($in, $out);
            fclose($out);

            $clip = "{$dir}/clip.mp4";
            $fadeOut = max(0, $duration - self::FADE);
            $this->ffmpeg(['-ss', (string) $start, '-t', (string) $duration, '-i', $source, '-an',
                '-vf', self::SCALE.',fps=30,fade=t=in:st=0:d='.self::FADE.",fade=t=out:st={$fadeOut}:d=".self::FADE,
                '-c:v', 'libx264', '-preset', 'slow', '-crf', '27', '-pix_fmt', 'yuv420p', '-movflags', '+faststart', $clip]);
            $poster = "{$dir}/poster.jpg";
            $this->ffmpeg(['-ss', (string) ($start + self::FADE + 0.2), '-i', $source, '-frames:v', '1', '-vf', self::SCALE, '-q:v', '4', $poster]);

            [$width, $height] = getimagesize($poster) ?: [null, null];
            $base = dirname($video->file_path).'/'.pathinfo($video->file_path, PATHINFO_FILENAME);
            foreach ([self::CLIP => [$clip, 'mp4', 'video/mp4'], self::POSTER => [$poster, 'jpg', 'image/jpeg']] as $name => [$file, $ext, $mime]) {
                $path = "{$base}_{$name}.{$ext}";
                $this->s3->putFile($file, $path, $visibility);
                MediaConversion::updateOrCreate(
                    ['media_id' => $video->id, 'conversion_name' => $name],
                    ['file_path' => $path, 'width' => $width, 'height' => $height, 'size' => filesize($file), 'mime_type' => $mime],
                );
            }
        } finally {
            File::deleteDirectory($dir);
        }
    }

    private function ffmpeg(array $args): void
    {
        Process::timeout(600)->run(['ffmpeg', '-v', 'error', '-y', ...$args])->throw();
    }
}
