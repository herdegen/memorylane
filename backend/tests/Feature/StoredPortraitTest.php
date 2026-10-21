<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\StoredPortraitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Portrait stocké (avatar) : fabriqué une fois, cadré sur le visage, refait
 * seulement quand la source change, indépendant de la photo d'origine.
 */
class StoredPortraitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Person $person;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->owner = User::factory()->create();
        $this->person = Person::factory()->create(['user_id' => $this->owner->id]);
    }

    private function jpeg(int $w = 1600, int $h = 1200): string
    {
        $img = imagecreatetruecolor($w, $h);
        imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 120, 140, 160));
        ob_start();
        imagejpeg($img);

        return ob_get_clean();
    }

    /** Photo avec le visage de la personne (confiance donnée). */
    private function photoWithFace(float $confidence, string $file): Media
    {
        Storage::put("media/{$file}", $this->jpeg());
        $media = Media::factory()->photo()->create(['user_id' => $this->owner->id]);
        $media->conversions()->create(['conversion_name' => 'large', 'file_path' => "media/{$file}", 'mime_type' => 'image/jpeg']);
        $media->detectedFaces()->create([
            'person_id' => $this->person->id,
            'bounding_box' => ['x' => 40, 'y' => 30, 'width' => 8, 'height' => 12],
            'status' => 'matched',
            'confidence' => $confidence,
            'provider' => 'faceapi',
        ]);

        return $media;
    }

    public function test_portrait_fabrique_depuis_le_meilleur_visage_et_servi_en_cache_long(): void
    {
        $this->photoWithFace(0.9, 'a.jpg');
        $person = $this->person->fresh();

        $this->assertNotNull($person->portrait_path, 'fait automatiquement à l\'association du visage');
        $img = new \Imagick();
        $img->readImageBlob(Storage::get($person->portrait_path));
        $this->assertSame([StoredPortraitService::WIDTH, StoredPortraitService::HEIGHT], [$img->getImageWidth(), $img->getImageHeight()]);

        $url = $person->avatarUrl();
        $this->assertStringContainsString("/people/{$person->id}/portrait?v=", $url);

        $this->actingAs($this->owner)->get($url)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('Cache-Control', 'immutable, max-age=31536000, private');

        $thumb = $this->actingAs($this->owner)->get($url.'&s=sm')->assertOk()->getContent();
        $img = new \Imagick();
        $img->readImageBlob($thumb);
        $this->assertSame([StoredPortraitService::THUMB, StoredPortraitService::THUMB], [$img->getImageWidth(), $img->getImageHeight()]);

        $this->assertFalse(app(StoredPortraitService::class)->refresh($person), 'rien à refaire');
    }

    public function test_photo_a_la_corbeille_portrait_refait_depuis_le_visage_suivant(): void
    {
        $this->photoWithFace(0.7, 'b.jpg');
        $best = $this->photoWithFace(0.95, 'c.jpg');
        $before = $this->person->fresh()->portrait_path;

        $best->delete();

        $after = $this->person->fresh()->portrait_path;
        $this->assertNotNull($after);
        $this->assertNotSame($before, $after);
        Storage::assertMissing($before);
        Storage::assertMissing(StoredPortraitService::thumbPath($before));
        Storage::assertExists($after);
        Storage::assertExists(StoredPortraitService::thumbPath($after));
    }

    public function test_plus_aucune_photo_plus_de_portrait(): void
    {
        $media = $this->photoWithFace(0.9, 'd.jpg');
        $path = $this->person->fresh()->portrait_path;

        $media->delete();

        $person = $this->person->fresh();
        $this->assertNull($person->portrait_path);
        $this->assertNull($person->avatarUrl());
        Storage::assertMissing($path);
    }

    public function test_photo_de_profil_choisie_prioritaire(): void
    {
        $this->photoWithFace(0.99, 'e.jpg');
        Storage::put('media/profil.jpg', $this->jpeg(800, 1200));
        $profil = Media::factory()->photo()->create(['user_id' => $this->owner->id]);
        $profil->conversions()->create(['conversion_name' => 'large', 'file_path' => 'media/profil.jpg', 'mime_type' => 'image/jpeg']);

        $this->person->update(['avatar_media_id' => $profil->id]);

        [$source] = app(StoredPortraitService::class)->source($this->person->fresh());
        $this->assertSame($profil->id, $source->id);
        $this->assertNotNull($this->person->fresh()->portrait_path);
    }

    public function test_cadre_3_4_borne_a_l_image(): void
    {
        $service = app(StoredPortraitService::class);

        [$w, $h, $left, $top] = $service->faceFrame(['x' => 40, 'y' => 30, 'width' => 8, 'height' => 12], 1600, 1200);
        $this->assertEqualsWithDelta(0.75, $w / $h, 0.01);
        $this->assertTrue($left >= 0 && $top >= 0 && $left + $w <= 1600 && $top + $h <= 1200);

        // Visage énorme dans le coin : le cadre rétrécit mais reste dans l'image.
        [$w, $h, $left, $top] = $service->faceFrame(['x' => 70, 'y' => 60, 'width' => 30, 'height' => 40], 1600, 1200);
        $this->assertTrue($left >= 0 && $top >= 0 && $left + $w <= 1600 && $top + $h <= 1200);
    }
}
