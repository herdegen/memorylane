<?php

namespace App\Services;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Portrait stocké d'une fiche : un JPEG 480×640 (3:4, adapté aux ronds comme
 * aux cartes de l'arbre) cadré sur le visage, fabriqué UNE fois depuis :
 *   1. la photo de profil choisie (hors corbeille), cadrée sur le visage de
 *      la personne s'il y est identifié, sinon au centre ;
 *   2. à défaut, son meilleur visage reconnu sur une photo hors corbeille.
 * Refait seulement quand sa source change (empreinte `portrait_source`).
 */
class StoredPortraitService
{
    public const WIDTH = 480;
    public const HEIGHT = 640;

    /** Vignette carrée (pastilles rondes ≤ 48 px), centrée sur le visage. */
    public const THUMB = 160;

    /** Hauteur du cadre = hauteur du visage × ce facteur (épaules, un peu d'air). */
    private const FACE_ZOOM = 2.6;

    /** Position verticale du centre du visage dans le cadre (0 = haut). */
    private const FACE_Y = 0.42;

    /**
     * Met le portrait à jour si sa source a changé. Renvoie vrai si un
     * fichier a été (re)fait ou retiré.
     */
    public function refresh(Person $person): bool
    {
        [$media, $face] = $this->source($person);
        $signature = $media ? $this->signature($media, $face) : null;

        if ($signature === $person->portrait_source && ($signature === null || $person->portrait_path)) {
            return false;
        }

        $old = $person->portrait_path;
        $path = null;

        if ($media) {
            $jpeg = $this->render($media, $face);
            if ($jpeg === null) {
                // Fichier source illisible : on garde l'ancien portrait plutôt qu'aucun.
                return false;
            }
            $path = "portraits/{$person->id}/".substr($signature, 0, 16).'.jpg';
            Storage::put($path, $jpeg, ['ContentType' => 'image/jpeg', 'visibility' => 'private']);
            Storage::put(self::thumbPath($path), $this->thumbnail($jpeg), ['ContentType' => 'image/jpeg', 'visibility' => 'private']);
        }

        $person->forceFill(['portrait_path' => $path, 'portrait_source' => $signature])->saveQuietly();

        if ($old && $old !== $path) {
            Storage::delete([$old, self::thumbPath($old)]);
        }

        return true;
    }

    /** Contenu du portrait stocké (ou de sa vignette carrée), ou null. */
    public function contents(Person $person, bool $thumb = false): ?string
    {
        if (! $person->portrait_path) {
            return null;
        }

        try {
            return Storage::get($thumb ? self::thumbPath($person->portrait_path) : $person->portrait_path)
                ?: ($thumb ? Storage::get($person->portrait_path) : null);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function thumbPath(string $path): string
    {
        return preg_replace('/\.jpg$/', '_sm.jpg', $path);
    }

    /** Carré centré sur le visage (placé à FACE_Y du portrait). */
    private function thumbnail(string $portrait): string
    {
        $img = new \Imagick();
        $img->readImageBlob($portrait);
        $top = (int) round(min(max(0, self::HEIGHT * self::FACE_Y - self::WIDTH / 2), self::HEIGHT - self::WIDTH));
        $img->cropImage(self::WIDTH, self::WIDTH, 0, $top);
        $img->setImagePage(0, 0, 0, 0);
        $img->resizeImage(self::THUMB, self::THUMB, \Imagick::FILTER_LANCZOS, 1);
        $img->setImageCompressionQuality(80);
        $jpeg = $img->getImageBlob();
        $img->clear();

        return $jpeg;
    }

    /**
     * Photo et visage sources.
     *
     * @return array{0: ?Media, 1: ?DetectedFace}
     */
    public function source(Person $person): array
    {
        $avatar = $person->avatar_media_id ? Media::with('conversions')->find($person->avatar_media_id) : null;

        if ($avatar) {
            $face = $person->detectedFaces()->usableForAvatar()
                ->where('media_id', $avatar->id)
                ->orderByDesc('confidence')
                ->first();

            return [$avatar, $face];
        }

        $face = $person->detectedFaces()->usableForAvatar()
            ->with('media.conversions')
            ->orderByDesc('confidence')
            ->first();

        return [$face?->media, $face];
    }

    private function signature(Media $media, ?DetectedFace $face): string
    {
        return hash('sha256', implode('|', [
            'v4', self::WIDTH, self::HEIGHT,
            $media->id,
            $face?->id,
            $face ? json_encode($face->bounding_box) : '',
        ]));
    }

    private function render(Media $media, ?DetectedFace $face): ?string
    {
        $conversion = $media->conversions->firstWhere('conversion_name', 'large')
            ?? $media->conversions->firstWhere('conversion_name', 'medium');

        try {
            $blob = Storage::get($conversion->file_path ?? $media->file_path);
            if (! $blob) {
                return null;
            }

            $img = new \Imagick();
            $img->readImageBlob($blob);
            $w = $img->getImageWidth();
            $h = $img->getImageHeight();

            $box = $face?->bounding_box;
            if (is_array($box) && isset($box['x'], $box['y'], $box['width'], $box['height'])) {
                [$cw, $ch, $left, $top] = $this->faceFrame($box, $w, $h);
                $img->cropImage($cw, $ch, $left, $top);
                $img->resizeImage(self::WIDTH, self::HEIGHT, \Imagick::FILTER_LANCZOS, 1);
            } else {
                // Pas de visage connu sur la photo choisie : recadrage centré.
                $img->cropThumbnailImage(self::WIDTH, self::HEIGHT);
            }

            $img->setImagePage(0, 0, 0, 0);
            $img->stripImage();
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(82);
            $img->setInterlaceScheme(\Imagick::INTERLACE_JPEG);
            $jpeg = $img->getImageBlob();
            $img->clear();

            return $jpeg;
        } catch (\Throwable $e) {
            Log::warning('Portrait : rendu impossible', ['media' => $media->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Cadre 3:4 autour du visage (boîte en % de l'image), borné à l'image.
     *
     * @return array{0: int, 1: int, 2: int, 3: int} largeur, hauteur, gauche, haut
     */
    public function faceFrame(array $box, int $w, int $h): array
    {
        $fw = $box['width'] / 100 * $w;
        $fh = $box['height'] / 100 * $h;
        $cx = $box['x'] / 100 * $w + $fw / 2;
        $cy = $box['y'] / 100 * $h + $fh / 2;

        $ratio = self::WIDTH / self::HEIGHT;
        $ch = max($fh * self::FACE_ZOOM, $fw * 1.9 / $ratio);
        $cw = $ch * $ratio;

        // Trop grand pour l'image : on réduit en gardant le ratio.
        $scale = min(1, $w / $cw, $h / $ch);
        $cw *= $scale;
        $ch *= $scale;

        $left = min(max(0, $cx - $cw / 2), $w - $cw);
        $top = min(max(0, $cy - $ch * self::FACE_Y), $h - $ch);

        return [(int) round($cw), (int) round($ch), (int) round($left), (int) round($top)];
    }
}
