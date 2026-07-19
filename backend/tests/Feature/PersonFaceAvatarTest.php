<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Avatar-visage de la fiche personne (étude visibilité, phase 1).
 *
 * Il était servi en 403 hors créateur → avatars cassés sur les fiches vues
 * depuis un autre compte. La fiche étant publique en lecture et l'identification
 * des visages commune à tous, l'avatar-visage doit être servi à tout compte
 * connecté (aligné sur l'avatar explicite).
 */
class PersonFaceAvatarTest extends TestCase
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

    private function personWithMatchedFace(User $owner): Person
    {
        $person = Person::factory()->create(['user_id' => $owner->id]);
        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $media->conversions()->create([
            'conversion_name' => 'medium',
            'file_path' => 'media/face_medium.jpg',
            'mime_type' => 'image/jpeg',
        ]);
        $media->detectedFaces()->create([
            'person_id' => $person->id,
            'bounding_box' => ['x' => 30, 'y' => 30, 'width' => 20, 'height' => 20],
            'status' => 'matched',
            'confidence' => 0.9,
            'provider' => 'faceapi',
        ]);

        return $person;
    }

    public function test_un_autre_compte_recoit_lavatar_visage(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::disk(config('filesystems.default'))->put('media/face_medium.jpg', $this->jpeg());

        $owner = User::factory()->create();
        $viewer = User::factory()->create();
        $person = $this->personWithMatchedFace($owner);

        $response = $this->actingAs($viewer)->get("/people/{$person->id}/face-avatar");

        $response->assertOk();
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
    }

    public function test_avatar_visage_exige_une_authentification(): void
    {
        $owner = User::factory()->create();
        $person = $this->personWithMatchedFace($owner);

        // Non authentifié → redirigé vers le login (pas servi).
        $this->get("/people/{$person->id}/face-avatar")->assertRedirect();
    }
}
