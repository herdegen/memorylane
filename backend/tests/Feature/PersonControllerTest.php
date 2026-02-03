<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_can_list_people(): void
    {
        Person::factory()->count(3)->create(['user_id' => $this->user->id]);
        Person::factory()->count(2)->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->getJson('/people');

        $response->assertStatus(200)
            ->assertJsonCount(3);
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

    public function test_cannot_show_other_users_person(): void
    {
        $person = Person::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->getJson("/people/{$person->id}");

        $response->assertStatus(403);
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
