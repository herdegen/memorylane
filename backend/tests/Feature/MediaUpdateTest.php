<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaUpdateTest extends TestCase
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

    public function test_can_update_media_title_and_description(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->putJson("/media/{$media->id}", [
            'title' => 'Mon titre',
            'description' => 'Ma description',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Media mis a jour']);

        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'title' => 'Mon titre',
            'description' => 'Ma description',
        ]);
    }

    public function test_can_update_title_only(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->putJson("/media/{$media->id}", [
            'title' => 'Nouveau titre',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'title' => 'Nouveau titre',
        ]);
    }

    public function test_can_clear_title_and_description(): void
    {
        $media = Media::factory()->create([
            'user_id' => $this->user->id,
            'title' => 'Existing title',
            'description' => 'Existing description',
        ]);

        $response = $this->actingAs($this->user)->putJson("/media/{$media->id}", [
            'title' => null,
            'description' => null,
        ]);

        $response->assertStatus(200);

        $media->refresh();
        $this->assertNull($media->title);
        $this->assertNull($media->description);
    }

    public function test_cannot_update_other_users_media(): void
    {
        $media = Media::factory()->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->putJson("/media/{$media->id}", [
            'title' => 'Hacked',
        ]);

        $response->assertStatus(403);
    }

    public function test_title_max_length_validation(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->putJson("/media/{$media->id}", [
            'title' => str_repeat('a', 256),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_description_max_length_validation(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->putJson("/media/{$media->id}", [
            'description' => str_repeat('a', 2001),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['description']);
    }

    public function test_show_media_includes_people(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->get("/media/{$media->id}");

        $response->assertStatus(200);
    }

    public function test_unauthenticated_cannot_update_media(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->putJson("/media/{$media->id}", [
            'title' => 'Should fail',
        ]);

        $response->assertStatus(401);
    }
}
