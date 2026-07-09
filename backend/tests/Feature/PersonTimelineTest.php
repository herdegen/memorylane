<?php

namespace Tests\Feature;

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
