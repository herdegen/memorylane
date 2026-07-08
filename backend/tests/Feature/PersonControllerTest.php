<?php

namespace Tests\Feature;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PersonControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    public function test_lists_all_people_tree_is_public(): void
    {
        // Arbre public : les fiches sont visibles par tous les connectés.
        Person::factory()->count(3)->create(['user_id' => $this->user->id]);
        Person::factory()->count(2)->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->getJson('/people');

        $response->assertStatus(200)
            ->assertJsonCount(5);
    }

    // --- Avatar depuis le visage tagué (#10) ---

    private function matchedFace(Person $person, Media $media): DetectedFace
    {
        return DetectedFace::create([
            'media_id' => $media->id,
            'person_id' => $person->id,
            'bounding_box' => ['x' => 25, 'y' => 25, 'width' => 20, 'height' => 20],
            'confidence' => 0.9,
            'provider' => 'faceapi',
            'status' => 'matched',
        ]);
    }

    public function test_index_avatar_falls_back_to_face_crop_when_no_avatar(): void
    {
        $withFace = Person::factory()->create(['user_id' => $this->user->id, 'avatar_media_id' => null]);
        $withNothing = Person::factory()->create(['user_id' => $this->user->id, 'avatar_media_id' => null]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);
        $this->matchedFace($withFace, $media);

        $response = $this->actingAs($this->user)->getJson('/people');
        $response->assertOk();

        $byId = collect($response->json())->keyBy('id');
        $this->assertStringContainsString("/people/{$withFace->id}/face-avatar", $byId[$withFace->id]['avatar_url']);
        $this->assertNull($byId[$withNothing->id]['avatar_url']);
    }

    public function test_face_avatar_endpoint_streams_a_jpeg_crop(): void
    {
        Storage::fake(config('filesystems.default'));

        // Vraie image sur le disque simulé.
        $im = new \Imagick();
        $im->newImage(400, 300, 'red');
        $im->setImageFormat('jpeg');
        Storage::disk(config('filesystems.default'))->put('photos/x.jpg', $im->getImageBlob());

        $media = Media::factory()->create(['user_id' => $this->user->id, 'file_path' => 'photos/x.jpg']);
        $person = Person::factory()->create(['user_id' => $this->user->id, 'avatar_media_id' => null]);
        $this->matchedFace($person, $media);

        $response = $this->actingAs($this->user)->get("/people/{$person->id}/face-avatar");

        $response->assertOk();
        $this->assertEquals('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertNotEmpty($response->getContent());
    }

    public function test_face_avatar_404_without_matched_face(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id, 'avatar_media_id' => null]);

        $this->actingAs($this->user)
            ->get("/people/{$person->id}/face-avatar")
            ->assertNotFound();
    }

    public function test_face_avatar_forbidden_for_other_user(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->otherUser)
            ->get("/people/{$person->id}/face-avatar")
            ->assertForbidden();
    }

    public function test_can_create_person(): void
    {
        $response = $this->actingAs($this->user)->postJson('/people', [
            'name' => 'Marie Dupont',
            'birth_date' => '1990-05-15',
            'notes' => 'Ma soeur',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('person.name', 'Marie Dupont')
            ->assertJsonPath('person.slug', 'marie-dupont');

        $this->assertDatabaseHas('people', [
            'user_id' => $this->user->id,
            'name' => 'Marie Dupont',
            'slug' => 'marie-dupont',
        ]);
    }

    public function test_create_person_requires_name(): void
    {
        $response = $this->actingAs($this->user)->postJson('/people', [
            'notes' => 'Sans nom',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_slug_is_auto_generated_and_unique(): void
    {
        Person::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Jean Dupont',
        ]);

        $response = $this->actingAs($this->user)->postJson('/people', [
            'name' => 'Jean Dupont',
        ]);

        $response->assertStatus(201);

        $person = Person::where('name', 'Jean Dupont')
            ->where('slug', '!=', 'jean-dupont')
            ->first();

        $this->assertNotNull($person);
        $this->assertStringStartsWith('jean-dupont', $person->slug);
    }

    public function test_can_show_own_person(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson("/people/{$person->id}");

        $response->assertStatus(200)
            ->assertJsonPath('person.name', $person->name);
    }

    public function test_can_view_other_users_person_tree_is_public(): void
    {
        // Lecture publique de l'arbre : voir la fiche d'autrui est autorisé
        // (l'édition reste interdite, cf. test_cannot_update_other_users_person).
        $person = Person::factory()->create(['user_id' => $this->otherUser->id]);

        $this->actingAs($this->user)->getJson("/people/{$person->id}")->assertOk();
    }

    public function test_public_person_does_not_leak_private_media(): void
    {
        // La fiche est publique mais ses photos privées ne doivent PAS fuiter.
        $person = Person::factory()->create(['user_id' => $this->otherUser->id]);
        $private = Media::factory()->create(['user_id' => $this->otherUser->id, 'type' => 'photo']);
        $private->people()->attach($person->id);

        $data = $this->actingAs($this->user)->getJson("/people/{$person->id}")->assertOk()->json();
        $this->assertCount(0, $data['media']['data']);
    }

    public function test_can_update_person(): void
    {
        $person = Person::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($this->user)->putJson("/people/{$person->id}", [
            'name' => 'New Name',
            'notes' => 'Updated notes',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('person.name', 'New Name');

        $this->assertDatabaseHas('people', [
            'id' => $person->id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
    }

    public function test_cannot_update_other_users_person(): void
    {
        $person = Person::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->putJson("/people/{$person->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_can_delete_person(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson("/people/{$person->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Personne supprimee']);

        $this->assertSoftDeleted('people', ['id' => $person->id]);
    }

    public function test_cannot_delete_other_users_person(): void
    {
        $person = Person::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->deleteJson("/people/{$person->id}");

        $response->assertStatus(403);
    }

    // --- Attach / Detach ---

    public function test_can_attach_person_to_media(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson('/people/attach', [
            'media_id' => $media->id,
            'person_id' => $person->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Personne ajoutee au media']);

        $this->assertDatabaseHas('media_person', [
            'media_id' => $media->id,
            'person_id' => $person->id,
        ]);
    }

    public function test_attaching_same_person_twice_does_not_duplicate(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson('/people/attach', [
            'media_id' => $media->id,
            'person_id' => $person->id,
        ]);

        $this->actingAs($this->user)->postJson('/people/attach', [
            'media_id' => $media->id,
            'person_id' => $person->id,
        ]);

        $this->assertEquals(1, $media->people()->count());
    }

    public function test_can_detach_person_from_media(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);
        $media->people()->attach($person->id);

        $response = $this->actingAs($this->user)->postJson('/people/detach', [
            'media_id' => $media->id,
            'person_id' => $person->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Personne retiree du media']);

        $this->assertDatabaseMissing('media_person', [
            'media_id' => $media->id,
            'person_id' => $person->id,
        ]);
    }

    public function test_cannot_attach_other_users_person_to_media(): void
    {
        $person = Person::factory()->create(['user_id' => $this->otherUser->id]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson('/people/attach', [
            'media_id' => $media->id,
            'person_id' => $person->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_cannot_attach_person_to_other_users_media(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $media = Media::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->postJson('/people/attach', [
            'media_id' => $media->id,
            'person_id' => $person->id,
        ]);

        $response->assertStatus(403);
    }

    public function test_validates_death_date_after_birth_date(): void
    {
        $response = $this->actingAs($this->user)->postJson('/people', [
            'name' => 'Test Person',
            'birth_date' => '2000-01-01',
            'death_date' => '1999-01-01',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['death_date']);
    }
}
