<?php

namespace App\Services;

use App\Models\Person;
use App\Services\Vision\FaceCropService;
use Illuminate\Support\Facades\Storage;

/**
 * Portrait carré d'une personne en JPEG (photo de profil, sinon visage
 * tagué), pour les contextes hors session : mail d'invitation, page
 * d'accueil d'un invité pas encore connecté.
 */
class PersonPortraitService
{
    public function __construct(private FaceCropService $faceCrops, private StoredPortraitService $stored) {}

    public function jpeg(Person $person, int $size = 240): ?string
    {
        try {
            // Portrait stocké (déjà cadré sur le visage) : simple carré.
            if ($blob = $this->stored->contents($person)) {
                $img = new \Imagick();
                $img->readImageBlob($blob);
                $img->cropThumbnailImage($size, $size);
                $jpeg = $img->getImageBlob();
                $img->clear();

                return $jpeg;
            }

            $person->loadMissing('avatar.conversions');
            if ($media = $person->avatar) {
                $conversion = $media->conversions->firstWhere('conversion_name', 'medium')
                    ?? $media->conversions->firstWhere('conversion_name', 'small');
                $blob = Storage::disk(config('filesystems.default'))->get($conversion->file_path ?? $media->file_path);
                if ($blob) {
                    $img = new \Imagick();
                    $img->readImageBlob($blob);
                    $img->autoOrient();
                    $img->cropThumbnailImage($size, $size);
                    $img->setImageFormat('jpeg');
                    $img->setImageCompressionQuality(85);
                    $jpeg = $img->getImageBlob();
                    $img->clear();

                    return $jpeg;
                }
            }

            $face = $person->detectedFaces()
                ->usableForAvatar()
                ->orderByDesc('confidence')
                ->first();

            return $face ? $this->faceCrops->cropJpeg($face, $size) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function dataUri(Person $person, int $size = 240): ?string
    {
        $jpeg = $this->jpeg($person, $size);

        return $jpeg ? 'data:image/jpeg;base64,'.base64_encode($jpeg) : null;
    }
}
