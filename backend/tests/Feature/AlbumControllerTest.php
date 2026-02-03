<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlbumControllerTest extends TestCase
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

    public function test_can_list_albums(): void
    {
        Album::factory()->count(3)->create(['user_id' => $this->user->id]);
        Album::factory()->count(2)->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->getJson('/albums');

        $response->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function test_can_create_album(): void
    {
        $response = $this->actingAs($this->user)->postJson('/albums', [
            'name' => 'Vacances 2025',
            'description' => 'Photos de vacances',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('album.name', 'Vacances 2025')
            ->assertJsonPath('album.slug', 'vacances-2025');

        $this->assertDatabaseHas('albums', [
            'user_id' => $this->user->id,
            'name' => 'Vacances 2025',
            'slug' => 'vacances-2025',
        ]);
    }

    public function test_create_album_requires_name(): void
    {
        $response = $this->actingAs($this->user)->postJson('/albums', [
            'description' => 'Sans nom',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    public function test_can_show_own_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->get("/albums/{$album->id}");

        $response->assertStatus(200);
    }

    public function test_cannot_show_other_users_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->get("/albums/{$album->id}");

        $response->assertStatus(403);
    }

    public function test_can_update_album(): void
    {
        $album = Album::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Old Name',
        ]);

        $response = $this->actingAs($this->user)->putJson("/albums/{$album->id}", [
            'name' => 'New Name',
            'description' => 'Updated description',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('album.name', 'New Name');

        $this->assertDatabaseHas('albums', [
            'id' => $album->id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
    }

    public function test_cannot_update_other_users_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->putJson("/albums/{$album->id}", [
            'name' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_can_delete_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson("/albums/{$album->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Album supprime avec succes']);

        $this->assertSoftDeleted('albums', ['id' => $album->id]);
    }

    public function test_cannot_delete_other_users_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->deleteJson("/albums/{$album->id}");

        $response->assertStatus(403);
    }

    // --- Media management ---

    public function test_can_add_media_to_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->user->id]);
        $media = Media::factory()->count(3)->create(['user_id' => $this->user->id]);

        $mediaIds = $media->pluck('id')->toArray();

        $response = $this->actingAs($this->user)->postJson("/albums/{$album->id}/media", [
            'media_ids' => $mediaIds,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('album_media', [
            'album_id' => $album->id,
            'media_id' => $mediaIds[0],
        ]);

        $album->refresh();
        $this->assertEquals(3, $album->media()->count());
    }

    public function test_adding_media_sets_cover_if_none(): void
    {
        $album = Album::factory()->create([
            'user_id' => $this->user->id,
            'cover_media_id' => null,
        ]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/albums/{$album->id}/media", [
            'media_ids' => [$media->id],
        ]);

        $album->refresh();
        $this->assertEquals($media->id, $album->cover_media_id);
    }

    public function test_can_remove_media_from_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->user->id]);
        $media = Media::factory()->count(3)->create(['user_id' => $this->user->id]);
        $album->media()->attach($media->pluck('id')->toArray());

        $response = $this->actingAs($this->user)->deleteJson("/albums/{$album->id}/media", [
            'media_ids' => [$media[0]->id],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('album_media', [
            'album_id' => $album->id,
            'media_id' => $media[0]->id,
        ]);

        $album->refresh();
        $this->assertEquals(2, $album->media()->count());
    }

    public function test_can_reorder_media_in_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->user->id]);
        $media = Media::factory()->count(3)->create(['user_id' => $this->user->id]);
        foreach ($media as $i => $m) {
            $album->media()->attach($m->id, ['order' => $i]);
        }

        $reversed = $media->reverse()->pluck('id')->toArray();

        $response = $this->actingAs($this->user)->putJson("/albums/{$album->id}/media/reorder", [
            'media_order' => $reversed,
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('album_media', [
            'album_id' => $album->id,
            'media_id' => $reversed[0],
            'order' => 0,
        ]);
    }

    // --- Sharing ---

    public function test_can_generate_share_token(): void
    {
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/albums/{$album->id}/share");

        $response->assertStatus(200)
            ->assertJsonStructure(['share_token', 'share_url']);

        $album->refresh();
        $this->assertNotNull($album->share_token);
    }

    public function test_can_revoke_share_token(): void
    {
        $album = Album::factory()->withShareToken()->create(['user_id' => $this->user->id]);
        $this->assertNotNull($album->share_token);

        $response = $this->actingAs($this->user)->deleteJson("/albums/{$album->id}/share");

        $response->assertStatus(200);

        $album->refresh();
        $this->assertNull($album->share_token);
    }

    public function test_can_access_shared_album_via_token(): void
    {
        $album = Album::factory()->withShareToken()->create(['user_id' => $this->otherUser->id]);

        $response = $this->get("/albums/shared/{$album->share_token}");

        $response->assertStatus(200);
    }

    public function test_cannot_access_shared_album_with_invalid_token(): void
    {
        $response = $this->get('/albums/shared/invalid-token-that-does-not-exist');

        $response->assertStatus(404);
    }

    public function test_cannot_manage_media_in_other_users_album(): void
    {
        $album = Album::factory()->create(['user_id' => $this->otherUser->id]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/albums/{$album->id}/media", [
            'media_ids' => [$media->id],
        ]);

        $response->assertStatus(403);
    }
}
