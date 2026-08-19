<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verrouille les 403 cross-user restants signalés par l'audit :
 * couverture/géoloc d'album, moments de vie.
 */
class CrossUserAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $intruder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
    }

    public function test_cannot_set_cover_on_someone_elses_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->owner->id]);
        $media = Media::factory()->create(['user_id' => $this->owner->id]);
        $album->media()->attach($media->id);

        $this->actingAs($this->intruder)
            ->postJson("/albums/{$album->id}/cover", ['media_id' => $media->id])
            ->assertStatus(403);

        $this->assertNull($album->fresh()->cover_media_id);
    }

    public function test_set_cover_rejects_media_not_in_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->owner->id]);
        $outsideMedia = Media::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->owner)
            ->postJson("/albums/{$album->id}/cover", ['media_id' => $outsideMedia->id])
            ->assertStatus(422);
    }

    public function test_cannot_geolocate_someone_elses_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->owner->id]);
        $media = Media::factory()->create(['user_id' => $this->owner->id]);
        $album->media()->attach($media->id);

        $this->actingAs($this->intruder)
            ->postJson("/albums/{$album->id}/geolocate", [
                'latitude' => 48.85,
                'longitude' => 2.35,
            ])
            ->assertStatus(403);

        $this->assertDatabaseMissing('media_metadata', ['media_id' => $media->id]);
    }

    public function test_cannot_update_or_delete_someone_elses_life_event(): void
    {
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        $event = LifeEvent::create([
            'person_id' => $person->id,
            'user_id' => $this->owner->id,
            'title' => 'Naissance',
            'event_date' => '1990-01-01',
        ]);

        $this->actingAs($this->intruder)
            ->putJson("/life-events/{$event->id}", ['title' => 'Piraté'])
            ->assertStatus(403);

        $this->actingAs($this->intruder)
            ->deleteJson("/life-events/{$event->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('life_events', ['id' => $event->id, 'title' => 'Naissance']);
    }

    public function test_admin_can_manage_life_events_of_others(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        $event = LifeEvent::create([
            'person_id' => $person->id,
            'user_id' => $this->owner->id,
            'title' => 'Mariage',
            'event_date' => '2015-06-20',
        ]);

        $this->actingAs($admin)
            ->putJson("/life-events/{$event->id}", [
                'title' => 'Mariage civil',
                'event_date' => '2015-06-20',
            ])
            ->assertStatus(200);

        $this->assertEquals('Mariage civil', $event->fresh()->title);
    }
}
