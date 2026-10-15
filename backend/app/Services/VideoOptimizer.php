<?php

namespace App\Services;

use Illuminate\Support\Facades\Process;

/**
 * Optimisation des vidéos importées dont l'original reste ailleurs (Google
 * Drive) : vieux formats (MPEG-2 de DVD ou de caméscope, DV, MPEG-4 ASP…),
 * débits démesurés, vidéos entrelacées ou au-delà du 1080p sont réencodées
 * en H.264 de bonne qualité, lisible partout (plus besoin de version web
 * séparée), typiquement 3 à 4 fois plus légère. Une vidéo déjà efficace est
 * gardée telle quelle : la réencoder ne ferait que perdre en qualité.
 */
class VideoOptimizer
{
    /** Qualité constante x264 : 22 ≈ visuellement fidèle à une source familiale. */
    private const CRF = 22;

    /**
     * Débit plafond de sortie selon la hauteur (bit/s) : une numérisation
     * VHS pleine de grain monterait sinon à 7-8 Mbit/s en SD (plus lourde que
     * l'original MPEG-2) pour reproduire fidèlement… le bruit.
     */
    private const MAX_OUTPUT_BITRATE = [576 => 2_500_000, 720 => 4_500_000, 1080 => 8_000_000];

    /** Débruitage modéré des vidéos SD (VHS, Hi8, DVD) : grain et chroma baveuse. */
    private const SD_DENOISE = 'hqdn3d=3:2.5:6:5';

    /**
     * Débit au-delà duquel une vidéo H.264 vaut d'être réencodée, selon sa
     * hauteur (bit/s) : ~2 × ce que donne CRF 21 pour une vidéo familiale.
     */
    private const H264_MAX_BITRATE = [576 => 3_000_000, 720 => 6_000_000, 1080 => 12_000_000];

    /**
     * @return array{codec: ?string, width: int, height: int, bitrate: int, interlaced: bool, duration: float, has_audio: bool, container: string}
     */
    public function probe(string $path): array
    {
        $json = json_decode(Process::timeout(120)->run([
            'ffprobe', '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
        ])->throw()->output(), true) ?? [];

        $video = collect($json['streams'] ?? [])->firstWhere('codec_type', 'video') ?? [];
        $fieldOrder = $video['field_order'] ?? 'progressive';

        return [
            'codec' => $video['codec_name'] ?? null,
            'width' => (int) ($video['width'] ?? 0),
            'height' => (int) ($video['height'] ?? 0),
            'bitrate' => (int) ($json['format']['bit_rate'] ?? $video['bit_rate'] ?? 0),
            'interlaced' => in_array($fieldOrder, ['tt', 'bb', 'tb', 'bt'], true),
            'duration' => (float) ($json['format']['duration'] ?? 0),
            'has_audio' => collect($json['streams'] ?? [])->contains('codec_type', 'audio'),
            'container' => (string) ($json['format']['format_name'] ?? ''),
        ];
    }

    /** Plafond de débit vidéo de sortie pour une source de cette hauteur (≤ 1080p après mise à l'échelle). */
    private function maxBitrate(int $height): int
    {
        return collect(self::MAX_OUTPUT_BITRATE)->first(fn ($bps, $h) => $height <= $h, max(self::MAX_OUTPUT_BITRATE));
    }

    /**
     * Garder l'original plutôt que la version réencodée ? Oui si l'original
     * est déjà lisible partout (H.264 en MP4/MOV, progressif) et que le
     * réencodage ne l'allège pas.
     */
    public function keepSource(array $source, string $sourcePath, string $outputPath): bool
    {
        $playable = $source['codec'] === 'h264' && str_contains($source['container'], 'mp4') && ! $source['interlaced'];

        return $playable && filesize($outputPath) >= filesize($sourcePath);
    }

    /** Faut-il réencoder ? Non pour une vidéo H.264 en MP4/MOV, progressive, ≤ 1080p, au débit raisonnable. */
    public function needsOptimization(array $probe): bool
    {
        if ($probe['codec'] !== 'h264' || ! str_contains($probe['container'], 'mp4') || $probe['interlaced']) {
            return true;
        }
        if ($probe['height'] > 1080) {
            return true;
        }
        $cap = collect(self::H264_MAX_BITRATE)->first(fn ($bps, $h) => $probe['height'] <= $h, max(self::H264_MAX_BITRATE));

        return $probe['bitrate'] > $cap;
    }

    /**
     * Réencode $input en MP4 H.264/AAC dans $output : dés-entrelacé si
     * besoin, pixels carrés (les vidéos PAL ont des pixels rectangulaires),
     * 1080p au plus, métadonnées (date de tournage) conservées.
     */
    public function optimize(string $input, string $output, ?array $probe = null): void
    {
        $probe ??= $this->probe($input);
        $sd = $probe['height'] > 0 && $probe['height'] <= 576;
        $filters = array_filter([
            $probe['interlaced'] ? 'bwdif=mode=send_frame' : null,
            $sd ? self::SD_DENOISE : null,
            'scale=trunc(iw*sar/2)*2:ih',
            'setsar=1',
            "scale=w='min(1920,iw)':h='min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
        ]);

        Process::timeout(4 * 3600)->run([
            'ffmpeg', '-v', 'error', '-y', '-i', $input,
            '-map', '0:v:0', '-map', '0:a:0?', '-map_metadata', '0',
            '-vf', implode(',', $filters),
            '-c:v', 'libx264', '-preset', 'medium', '-crf', (string) self::CRF, '-profile:v', 'high', '-pix_fmt', 'yuv420p',
            '-maxrate', (string) $this->maxBitrate($probe['height']), '-bufsize', (string) (2 * $this->maxBitrate($probe['height'])),
            '-c:a', 'aac', '-b:a', '160k', '-ac', '2',
            '-movflags', '+faststart',
            $output,
        ])->throw();
    }
}
