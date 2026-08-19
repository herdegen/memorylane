<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Modification de masse depuis la galerie : date de prise de vue et
 * géolocalisation. Seuls les médias du demandeur sont modifiés.
 */
class MediaBulkUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    public function test_can_bulk_update_taken_at_on_own_media(): void
    {
        $media = Media::factory()->count(3)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson('/media/bulk/taken-at', [
            'media_ids' => $media->pluck('id')->all(),
            'taken_at' => '1998-07-12',
        ]);

        $response->assertStatus(200)
            ->assertJson(['updated' => 3, 'skipped' => 0]);

        foreach ($media as $m) {
            $this->assertEquals('1998-07-12', $m->fresh()->taken_at->format('Y-m-d'));
        }
    }

    public function test_bulk_taken_at_skips_media_of_other_users(): void
    {
        $other = User::factory()->create();
        $mine = Media::factory()->create(['user_id' => $this->user->id]);
        $foreign = Media::factory()->create(['user_id' => $other->id, 'taken_at' => '2020-01-01']);

        $response = $this->actingAs($this->user)->postJson('/media/bulk/taken-at', [
            'media_ids' => [$mine->id, $foreign->id],
            'taken_at' => '1998-07-12',
        ]);

        $response->assertStatus(200)
            ->assertJson(['updated' => 1, 'skipped' => 1]);

        $this->assertEquals('2020-01-01', $foreign->fresh()->taken_at->format('Y-m-d'));
    }

    public function test_bulk_taken_at_validates_input(): void
    {
        $this->actingAs($this->user)->postJson('/media/bulk/taken-at', [
            'media_ids' => [],
            'taken_at' => '1998-07-12',
        ])->assertStatus(422);

        $media = Media::factory()->create(['user_id' => $this->user->id]);
        $this->actingAs($this->user)->postJson('/media/bulk/taken-at', [
            'media_ids' => [$media->id],
            'taken_at' => 'pas-une-date',
        ])->assertStatus(422);
    }

    public function test_can_bulk_update_geolocation_on_own_media(): void
    {
        $withMeta = Media::factory()->create(['user_id' => $this->user->id]);
        $withMeta->metadata()->create(['latitude' => 1, 'longitude' => 1]);
        $without = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson('/media/bulk/geolocation', [
            'media_ids' => [$withMeta->id, $without->id],
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);

        $response->assertStatus(200)
            ->assertJson(['updated' => 2, 'skipped' => 0]);

        // Ligne metadata existante mise à jour, ligne manquante créée
        $this->assertDatabaseHas('media_metadata', ['media_id' => $withMeta->id, 'latitude' => 48.8566]);
        $this->assertDatabaseHas('media_metadata', ['media_id' => $without->id, 'longitude' => 2.3522]);
    }

    public function test_bulk_geolocation_skips_media_of_other_users(): void
    {
        $other = User::factory()->create();
        $foreign = Media::factory()->create(['user_id' => $other->id]);

        $response = $this->actingAs($this->user)->postJson('/media/bulk/geolocation', [
            'media_ids' => [$foreign->id],
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);

        $response->assertStatus(200)
            ->assertJson(['updated' => 0, 'skipped' => 1]);

        $this->assertDatabaseMissing('media_metadata', ['media_id' => $foreign->id]);
    }

    public function test_bulk_endpoints_require_auth(): void
    {
        $this->postJson('/media/bulk/taken-at', [])->assertStatus(401);
        $this->postJson('/media/bulk/geolocation', [])->assertStatus(401);
    }
}
