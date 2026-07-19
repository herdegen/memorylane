<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retrait de médias d'un album (bug boutons/permissions) : le propriétaire de
 * l'album retire tout, un contributeur ne retire QUE ses propres médias.
 */
class AlbumRemoveMediaTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
    }

    public function test_le_proprietaire_retire_nimporte_quel_media(): void
    {
        $album = Album::factory()->create(['user_id' => $this->owner->id]);
        $mine = Media::factory()->photo()->create(['user_id' => $this->owner->id]);
        $theirs = Media::factory()->photo()->create(['user_id' => $this->other->id]);
        $album->media()->attach([$mine->id, $theirs->id]);

        $this->actingAs($this->owner)
            ->deleteJson("/albums/{$album->id}/media", ['media_ids' => [$mine->id, $theirs->id]])
            ->assertOk()
            ->assertJsonPath('removed', 2);

        $this->assertSame(0, $album->media()->count());
    }

    public function test_un_non_proprietaire_ne_retire_que_ses_propres_medias(): void
    {
        $album = Album::factory()->create(['user_id' => $this->owner->id]);
        $ownerMedia = Media::factory()->photo()->create(['user_id' => $this->owner->id]);
        $otherMedia = Media::factory()->photo()->create(['user_id' => $this->other->id]);
        $album->media()->attach([$ownerMedia->id, $otherMedia->id]);

        // L'autre compte tente de retirer les deux : seul le sien part.
        $this->actingAs($this->other)
            ->deleteJson("/albums/{$album->id}/media", ['media_ids' => [$ownerMedia->id, $otherMedia->id]])
            ->assertOk()
            ->assertJsonPath('removed', 1);

        $this->assertTrue($album->media()->where('media.id', $ownerMedia->id)->exists());
        $this->assertFalse($album->media()->where('media.id', $otherMedia->id)->exists());
    }

    public function test_un_non_proprietaire_sans_media_a_lui_est_refuse(): void
    {
        $album = Album::factory()->create(['user_id' => $this->owner->id]);
        $ownerMedia = Media::factory()->photo()->create(['user_id' => $this->owner->id]);
        $album->media()->attach($ownerMedia->id);

        $this->actingAs($this->other)
            ->deleteJson("/albums/{$album->id}/media", ['media_ids' => [$ownerMedia->id]])
            ->assertForbidden();

        $this->assertTrue($album->media()->where('media.id', $ownerMedia->id)->exists());
    }
}
