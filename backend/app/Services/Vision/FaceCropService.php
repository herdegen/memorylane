<?php

namespace App\Services\Vision;

use App\Models\DetectedFace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Recadrage carré d'un visage détecté (boîte en % de l'image), servi comme
 * avatar-visage (PersonController::faceAvatar) et comme vignette des quêtes
 * « qui est-ce ? » (VisionController::faceCrop). Null si l'image source est
 * introuvable ou illisible.
 */
class FaceCropService
{
    /**
     * Recadrage carré du visage, en cache tant que le visage ne change pas
     * (clé liée à sa date de mise à jour) : l'avatar n'est plus recalculé
     * (téléchargement + découpe) à chaque affichage.
     */
    public function cropJpeg(DetectedFace $face, int $size = 256): ?string
    {
        $key = "face-crop:{$face->id}:{$size}:".($face->updated_at?->timestamp ?? 0);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return base64_decode($cached);
        }

        $blob = $this->renderCrop($face, $size);
        if ($blob !== null) {
            Cache::put($key, base64_encode($blob), now()->addDays(30));
        }

        return $blob;
    }

    private function renderCrop(DetectedFace $face, int $size): ?string
    {
        $face->loadMissing('media.conversions');
        $media = $face->media;

        if (! $media || ! is_array($face->bounding_box)) {
            return null;
        }

        $conversion = $media->conversions->firstWhere('conversion_name', 'medium')
            ?? $media->conversions->firstWhere('conversion_name', 'large');
        $path = $conversion->file_path ?? $media->file_path;

        $disk = config('filesystems.default');

        $box = $face->bounding_box;

        try {
            $blob = Storage::disk($disk)->get($path);
            if (! $blob) {
                return null;
            }
            $img = new \Imagick();
            $img->readImageBlob($blob);

            $w = $img->getImageWidth();
            $h = $img->getImageHeight();

            // Carré centré sur le visage, avec une marge autour (× 1.6).
            $bw = ($box['width'] / 100) * $w;
            $bh = ($box['height'] / 100) * $h;
            $cx = ($box['x'] / 100) * $w + $bw / 2;
            $cy = ($box['y'] / 100) * $h + $bh / 2;
            $side = (int) min(max($bw, $bh) * 1.6, $w, $h);
            $left = (int) max(0, min($cx - $side / 2, $w - $side));
            $top = (int) max(0, min($cy - $side / 2, $h - $side));

            $img->cropImage($side, $side, $left, $top);
            $img->resizeImage($size, $size, \Imagick::FILTER_LANCZOS, 1);
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(85);
            $blob = $img->getImageBlob();
            $img->clear();
        } catch (\Throwable $e) {
            return null;
        }

        return $blob;
    }
}
