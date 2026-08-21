<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'user']);
        $this->otherUser = User::factory()->create(['role' => 'user']);
    }

    public function test_show_returns_siblings(): void
    {
        $father = Person::factory()->create(['user_id' => $this->user->id]);
        $me = Person::factory()->create(['user_id' => $this->user->id, 'father_id' => $father->id]);
        $sibling = Person::factory()->create(['user_id' => $this->user->id, 'father_id' => $father->id]);
        $unrelated = Person::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson("/people/{$me->id}");

        $response->assertOk();
        $ids = collect($response->json('siblings'))->pluck('id');
        $this->assertTrue($ids->contains($sibling->id));
        $this->assertFalse($ids->contains($me->id));
        $this->assertFalse($ids->contains($unrelated->id));
    }

    public function test_timeline_is_chronological_with_events_and_photos(): void
    {
        $person = Person::factory()->create([
            'user_id' => $this->user->id,
            'birth_date' => '1980-01-01',
        ]);

        LifeEvent::create([
            'person_id' => $person->id,
            'user_id' => $this->user->id,
            'type' => 'job',
            'title' => 'Premier emploi',
            'event_date' => '2010-05-05',
        ]);

        $photo = Media::factory()->photo()->create([
            'user_id' => $this->user->id,
            'taken_at' => '2000-03-03',
        ]);
        $person->media()->attach($photo->id);

        $response = $this->actingAs($this->user)->getJson("/people/{$person->id}/timeline");

        $response->assertOk();
        $kinds = collect($response->json())->pluck('kind')->all();

        // Ordre chronologique : naissance (1980) < photo (2000) < emploi (2010)
        $this->assertSame(['birth', 'photo', 'job'], $kinds);
    }

    public function test_timeline_photo_carries_its_accessible_albums(): void
    {
        // Le front regroupe la frise par album ; chaque item photo doit donc
        // porter ses albums accessibles (issue #32).
        $person = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null, 'death_date' => null]);
        $album = Album::factory()->create(['user_id' => $this->user->id, 'name' => 'Vacances']);
        $photo = Media::factory()->photo()->create(['user_id' => $this->user->id, 'taken_at' => '2018-07-01']);
        $person->media()->attach($photo->id);
        $album->media()->attach($photo->id);

        $data = $this->actingAs($this->user)->getJson("/people/{$person->id}/timeline")->assertOk()->json();

        $this->assertCount(1, $data);
        $this->assertSame('photo', $data[0]['kind']);
        $this->assertSame([['id' => $album->id, 'name' => 'Vacances']], $data[0]['albums']);
    }

    public function test_timeline_excludes_undated_photos(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null, 'death_date' => null]);
        $photo = Media::factory()->photo()->create(['user_id' => $this->user->id, 'taken_at' => null]);
        $person->media()->attach($photo->id);

        $response = $this->actingAs($this->user)->getJson("/people/{$person->id}/timeline");

        $response->assertOk()->assertJsonCount(0);
    }

    public function test_owner_can_create_moment(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/people/{$person->id}/events", [
            'type' => 'job',
            'title' => 'Boulanger',
            'event_date' => '2015-09-01',
        ])->assertStatus(201);

        $this->assertDatabaseHas('life_events', [
            'person_id' => $person->id,
            'title' => 'Boulanger',
            'type' => 'job',
        ]);
    }

    public function test_moment_avec_fete_album_et_position(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/people/{$person->id}/events", [
            'type' => 'bapteme',
            'title' => 'Baptême de Camille',
            'event_date' => '2019-06-02',
            'place' => 'Église Saint-Pierre, Lyon',
            'latitude' => 45.7578,
            'longitude' => 4.832,
            'album_id' => $album->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('life_events', [
            'person_id' => $person->id,
            'type' => 'bapteme',
            'album_id' => $album->id,
        ]);

        // La frise expose l'album lié et la position (animation carte à venir).
        $timeline = $this->actingAs($this->user)
            ->getJson("/people/{$person->id}/timeline")
            ->assertOk()
            ->json();

        $event = collect($timeline)->firstWhere('kind', 'bapteme');
        $this->assertNotNull($event);
        $this->assertSame($album->id, $event['album']['id']);
        $this->assertEqualsWithDelta(45.7578, $event['latitude'], 0.0001);
        $this->assertEqualsWithDelta(4.832, $event['longitude'], 0.0001);
    }

    public function test_moment_refuse_un_album_inaccessible(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $foreignAlbum = Album::factory()->create(['user_id' => $this->otherUser->id, 'is_public' => false]);

        $this->actingAs($this->user)->postJson("/people/{$person->id}/events", [
            'type' => 'fete',
            'title' => 'Fête pirate',
            'event_date' => '2020-01-01',
            'album_id' => $foreignAlbum->id,
        ])->assertStatus(403);
    }

    public function test_moment_refuse_une_latitude_sans_longitude(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/people/{$person->id}/events", [
            'title' => 'Moment boiteux',
            'event_date' => '2020-01-01',
            'latitude' => 45.0,
        ])->assertStatus(422)->assertJsonValidationErrors(['longitude']);
    }

    public function test_moment_refuse_un_type_inconnu(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/people/{$person->id}/events", [
            'type' => 'sous_moment_pirate',
            'title' => 'Type invalide',
            'event_date' => '2020-01-01',
        ])->assertStatus(422)->assertJsonValidationErrors(['type']);
    }

    public function test_moment_requires_title_and_date(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/people/{$person->id}/events", [
            'title' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['title', 'event_date']);
    }

    public function test_non_owner_non_admin_cannot_create_moment(): void
    {
        $person = Person::factory()->create(['user_id' => $this->otherUser->id]);

        $this->actingAs($this->user)->postJson("/people/{$person->id}/events", [
            'title' => 'X',
            'event_date' => '2020-01-01',
        ])->assertStatus(403);
    }

    public function test_admin_can_create_moment_on_others_person(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $person = Person::factory()->create(['user_id' => $this->otherUser->id]);

        $this->actingAs($admin)->postJson("/people/{$person->id}/events", [
            'title' => 'Diplôme',
            'event_date' => '2005-06-01',
        ])->assertStatus(201);
    }

    public function test_owner_can_update_and_delete_moment(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $event = LifeEvent::create([
            'person_id' => $person->id,
            'user_id' => $this->user->id,
            'type' => 'moment',
            'title' => 'Ancien',
            'event_date' => '2001-01-01',
        ]);

        $this->actingAs($this->user)->putJson("/life-events/{$event->id}", [
            'title' => 'Nouveau',
            'event_date' => '2001-01-01',
        ])->assertOk();
        $this->assertDatabaseHas('life_events', ['id' => $event->id, 'title' => 'Nouveau']);

        $this->actingAs($this->user)->deleteJson("/life-events/{$event->id}")->assertOk();
        $this->assertDatabaseMissing('life_events', ['id' => $event->id]);
    }
}
