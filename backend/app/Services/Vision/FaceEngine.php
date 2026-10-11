<?php

namespace App\Services\Vision;

use App\Models\Media;
use App\Services\S3Service;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Client du service InsightFace (docker/faces) : envoie l'image d'un média
 * (jamais hors du serveur) et reçoit les visages détectés — boîte, score,
 * empreinte ArcFace 512 normée, âge estimé.
 */
class FaceEngine
{
    public function __construct(private S3Service $s3) {}

    public static function enabled(): bool
    {
        return config('memorylane.faces.engine') === 'insightface';
    }

    /**
     * Image analysée : l'original s'il est lisible (JPEG, PNG, WebP), sinon la
     * plus grande conversion (HEIC, image extraite d'une vidéo). Mêmes
     * proportions et même orientation que l'affichage.
     */
    public function sourcePath(Media $media): ?string
    {
        if ($media->type === 'photo' && in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return $media->file_path;
        }
        $media->loadMissing('conversions');
        foreach (['large', 'web', 'medium', 'small'] as $name) {
            $conversion = $media->conversions->firstWhere('conversion_name', $name);
            if ($conversion && ! str_ends_with(strtolower($conversion->file_path), '.mp4')) {
                return $conversion->file_path;
            }
        }

        return null;
    }

    /**
     * Visages détectés, boîtes converties en % de l'image.
     *
     * @return list<array{box: array{x: float, y: float, width: float, height: float}, score: float, embedding: list<float>, age: ?int, px: float}>|null
     */
    public function analyze(Media $media): ?array
    {
        $data = $this->post('/analyze', $media);
        if ($data === null) {
            return null;
        }

        return array_map(fn ($f) => $this->normalize($f, $data['width'], $data['height']), $data['faces'] ?? []);
    }

    /**
     * Empreinte d'une zone (en %) dessinée à la main.
     *
     * @param  array{x: float, y: float, width: float, height: float}  $box
     * @return array{embedding: list<float>, age: ?int, source: string}|null
     */
    public function embedBox(Media $media, array $box): ?array
    {
        $query = http_build_query([
            'pct' => 1,
            'x1' => $box['x'], 'y1' => $box['y'],
            'x2' => $box['x'] + $box['width'], 'y2' => $box['y'] + $box['height'],
        ]);
        $data = $this->post('/embed?'.$query, $media);
        $face = $data['face'] ?? null;

        return $face ? ['embedding' => $face['embedding'], 'age' => $face['age'], 'source' => $face['source']] : null;
    }

    /**
     * Empreintes de plusieurs zones (en %) d'une même photo, en un envoi, sans
     * nouvelle détection sur toute la photo (zones déjà non retrouvées).
     *
     * @param  list<array{x: float, y: float, width: float, height: float}>  $boxes
     * @return list<array{embedding: list<float>, age: ?int, source: string}|null>
     */
    public function embedBoxes(Media $media, array $boxes): array
    {
        if ($boxes === []) {
            return [];
        }
        $query = http_build_query([
            'pct' => 1, 'full' => 0,
            'boxes' => json_encode(array_map(fn ($b) => [$b['x'], $b['y'], $b['x'] + $b['width'], $b['y'] + $b['height']], array_values($boxes))),
        ]);
        $faces = $this->post('/embed-many?'.$query, $media)['faces'] ?? [];

        return array_map(fn ($f) => $f ? ['embedding' => $f['embedding'], 'age' => $f['age'], 'source' => $f['source']] : null, $faces);
    }

    private function post(string $path, Media $media): ?array
    {
        $source = $this->sourcePath($media);
        $bytes = $source ? Storage::disk($this->s3->getDisk())->get($source) : null;
        if (! $bytes) {
            return null;
        }

        return Http::timeout(180)
            ->withBody($bytes, 'application/octet-stream')
            ->post(rtrim(config('memorylane.faces.service_url'), '/').$path)
            ->throw()
            ->json();
    }

    private function normalize(array $face, int $width, int $height): array
    {
        [$x1, $y1, $x2, $y2] = $face['box'];
        $x1 = max(0, $x1);
        $y1 = max(0, $y1);
        $x2 = min($width, $x2);
        $y2 = min($height, $y2);

        return [
            'box' => [
                'x' => round($x1 / $width * 100, 3),
                'y' => round($y1 / $height * 100, 3),
                'width' => round(($x2 - $x1) / $width * 100, 3),
                'height' => round(($y2 - $y1) / $height * 100, 3),
            ],
            'score' => $face['score'],
            'embedding' => $face['embedding'],
            'age' => $face['age'],
            'px' => min($x2 - $x1, $y2 - $y1),
        ];
    }
}
