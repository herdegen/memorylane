<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Photo de profil envoyée depuis la fiche : nouvelle photo, sur la fiche,
 * photo de profil ; refusée aux comptes qui ne modifient pas l'arbre.
 */
class PersonAvatarUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_envoyer_une_photo_de_profil_depuis_la_fiche(): void
    {
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id, 'name' => 'Mamie']);

        $this->actingAs($user)->post("/people/{$person->id}/avatar", [
            'photo' => UploadedFile::fake()->image('portrait.jpg', 800, 1000),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('message', 'Photo de profil enregistrée.');

        $media = Media::sole();
        $this->assertSame($user->id, $media->user_id);
        $this->assertSame('photo', $media->type);
        $this->assertSame($media->id, $person->fresh()->avatar_media_id);
        $this->assertTrue($media->people()->whereKey($person->id)->exists());
    }

    public function test_pas_pour_un_compte_tablette_ni_un_fichier_qui_n_est_pas_une_photo(): void
    {
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->post("/people/{$person->id}/avatar", [
            'photo' => UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $user->forceFill(['simple_mode' => true])->save();
        $this->actingAs($user)->post("/people/{$person->id}/avatar", [
            'photo' => UploadedFile::fake()->image('portrait.jpg'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame(0, Media::count());
    }
}
