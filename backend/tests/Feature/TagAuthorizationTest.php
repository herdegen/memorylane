<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verrouille les règles d'autorisation du TagController :
 * - attach/detach/mediaTags sont limités par la MediaPolicy ;
 * - update/destroy d'un tag global sont réservés aux admins.
 */
class TagAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $other;

    protected User $admin;

    protected Media $media;

    protected Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->other = User::factory()->create();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->media = Media::factory()->create(['user_id' => $this->owner->id]);
        $this->tag = Tag::factory()->create(['name' => 'Vacances']);
    }

    public function test_cannot_attach_tag_to_someone_elses_media(): void
    {
        $response = $this->actingAs($this->other)->postJson('/tags/attach', [
            'media_id' => $this->media->id,
            'tag_id' => $this->tag->id,
        ]);

        $response->assertStatus(403);
        $this->assertEquals(0, $this->media->tags()->count());
    }

    public function test_cannot_detach_tag_from_someone_elses_media(): void
    {
        $this->media->tags()->attach($this->tag->id);

        $response = $this->actingAs($this->other)->postJson('/tags/detach', [
            'media_id' => $this->media->id,
            'tag_id' => $this->tag->id,
        ]);

        $response->assertStatus(403);
        $this->assertEquals(1, $this->media->tags()->count());
    }

    public function test_cannot_list_tags_of_inaccessible_media(): void
    {
        $this->media->tags()->attach($this->tag->id);

        $this->actingAs($this->other)
            ->getJson("/tags/media/{$this->media->id}")
            ->assertStatus(403);
    }

    public function test_owner_can_still_list_tags_of_own_media(): void
    {
        $this->media->tags()->attach($this->tag->id);

        $this->actingAs($this->owner)
            ->getJson("/tags/media/{$this->media->id}")
            ->assertStatus(200)
            ->assertJsonFragment(['name' => 'Vacances']);
    }

    public function test_non_admin_cannot_update_global_tag(): void
    {
        $this->actingAs($this->other)
            ->putJson("/tags/{$this->tag->id}", ['name' => 'Renommé'])
            ->assertStatus(403);

        $this->assertDatabaseHas('tags', ['id' => $this->tag->id, 'name' => 'Vacances']);
    }

    public function test_non_admin_cannot_delete_global_tag(): void
    {
        $this->actingAs($this->other)
            ->deleteJson("/tags/{$this->tag->id}")
            ->assertStatus(403);

        $this->assertDatabaseHas('tags', ['id' => $this->tag->id]);
    }

    public function test_admin_can_update_and_delete_global_tag(): void
    {
        $this->actingAs($this->admin)
            ->putJson("/tags/{$this->tag->id}", ['name' => 'Renommé'])
            ->assertStatus(200);

        $this->assertDatabaseHas('tags', ['id' => $this->tag->id, 'name' => 'Renommé']);

        $this->actingAs($this->admin)
            ->deleteJson("/tags/{$this->tag->id}")
            ->assertStatus(200);

        $this->assertDatabaseMissing('tags', ['id' => $this->tag->id]);
    }
}
