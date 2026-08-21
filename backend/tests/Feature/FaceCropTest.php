<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Crop d'un visage détecté (GET /vision/faces/{face}/crop) — vignette des
 * quêtes « qui est-ce ? ». Autorisé à quiconque peut voir le média.
 */
class FaceCropTest extends TestCase
{
    use RefreshDatabase;

    private function jpeg(int $w = 400, int $h = 400): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 120, 140, 160));
        ob_start();
        imagejpeg($img);
        $bin = ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    private function faceOnMedia(User $owner)
    {
        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $media->conversions()->create([
            'conversion_name' => 'medium',
            'file_path' => 'media/crop_medium.jpg',
            'mime_type' => 'image/jpeg',
        ]);

        return $media->detectedFaces()->create([
            'bounding_box' => ['x' => 30, 'y' => 30, 'width' => 20, 'height' => 20],
            'status' => 'unmatched',
            'provider' => 'faceapi',
        ]);
    }

    public function test_crop_de_visage_refuse_sans_acces_au_media(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();
        $face = $this->faceOnMedia($owner);

        $this->actingAs($stranger)->get("/vision/faces/{$face->id}/crop")->assertForbidden();
    }

    public function test_crop_de_visage_renvoie_une_image_carree(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::disk(config('filesystems.default'))->put('media/crop_medium.jpg', $this->jpeg());

        $owner = User::factory()->create();
        $face = $this->faceOnMedia($owner);

        $response = $this->actingAs($owner)->get("/vision/faces/{$face->id}/crop");

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));

        [$w, $h] = getimagesizefromstring($response->getContent());
        $this->assertSame(256, $w);
        $this->assertSame(256, $h);
    }
}
