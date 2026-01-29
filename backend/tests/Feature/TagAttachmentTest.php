<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Media $media;
    protected Tag $tag;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test data
        $this->user = User::factory()->create();
        $this->media = Media::factory()->create(['user_id' => $this->user->id]);
        $this->tag = Tag::factory()->create(['name' => 'Test Tag']);
    }

    /**
     * Test attaching a tag to media.
     */
    public function test_can_attach_tag_to_media(): void
    {
        $response = $this->postJson('/tags/attach', [
            'media_id' => $this->media->id,
            'tag_id' => $this->tag->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Tag attached successfully']);

        $this->assertDatabaseHas('taggables', [
            'tag_id' => $this->tag->id,
            'taggable_id' => $this->media->id,
            'taggable_type' => Media::class,
        ]);
    }

    /**
     * Test attaching the same tag twice doesn't create duplicates.
     */
    public function test_attaching_same_tag_twice_does_not_duplicate(): void
    {
        // Attach tag first time
        $this->postJson('/tags/attach', [
            'media_id' => $this->media->id,
            'tag_id' => $this->tag->id,
        ])->assertStatus(200);

        // Attach same tag second time
        $this->postJson('/tags/attach', [
            'media_id' => $this->media->id,
            'tag_id' => $this->tag->id,
        ])->assertStatus(200);

        // Should only have one record in pivot table
        $this->assertEquals(1, $this->media->tags()->count());
    }

    /**
     * Test detaching a tag from media.
     */
    public function test_can_detach_tag_from_media(): void
    {
        // First attach the tag
        $this->media->tags()->attach($this->tag->id);

        $response = $this->postJson('/tags/detach', [
            'media_id' => $this->media->id,
            'tag_id' => $this->tag->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Tag detached successfully']);

        $this->assertDatabaseMissing('taggables', [
            'tag_id' => $this->tag->id,
            'taggable_id' => $this->media->id,
            'taggable_type' => Media::class,
        ]);
    }

    /**
     * Test attaching tag validates media_id.
     */
    public function test_attach_validates_media_id(): void
    {
        // Use a valid UUID format but non-existent media
        $response = $this->postJson('/tags/attach', [
            'media_id' => '00000000-0000-0000-0000-000000000000',
            'tag_id' => $this->tag->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['media_id']);
    }

    /**
     * Test attaching tag validates tag_id.
     */
    public function test_attach_validates_tag_id(): void
    {
        $response = $this->postJson('/tags/attach', [
            'media_id' => $this->media->id,
            'tag_id' => 99999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tag_id']);
    }

    /**
     * Test attaching tag requires both media_id and tag_id.
     */
    public function test_attach_requires_both_ids(): void
    {
        $response = $this->postJson('/tags/attach', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['media_id', 'tag_id']);
    }

    /**
     * Test detaching tag validates media_id.
     */
    public function test_detach_validates_media_id(): void
    {
        // Use a valid UUID format but non-existent media
        $response = $this->postJson('/tags/detach', [
            'media_id' => '00000000-0000-0000-0000-000000000000',
            'tag_id' => $this->tag->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['media_id']);
    }

    /**
     * Test detaching tag validates tag_id.
     */
    public function test_detach_validates_tag_id(): void
    {
        $response = $this->postJson('/tags/detach', [
            'media_id' => $this->media->id,
            'tag_id' => 99999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['tag_id']);
    }

    /**
     * Test getting tags for a specific media.
     */
    public function test_can_get_media_tags(): void
    {
        // Attach multiple tags
        $tag1 = Tag::factory()->create(['name' => 'Tag 1']);
        $tag2 = Tag::factory()->create(['name' => 'Tag 2']);

        $this->media->tags()->attach([$tag1->id, $tag2->id]);

        $response = $this->getJson("/tags/media/{$this->media->id}");

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['name' => 'Tag 1'])
            ->assertJsonFragment(['name' => 'Tag 2']);
    }

    /**
     * Test media can have multiple tags.
     */
    public function test_media_can_have_multiple_tags(): void
    {
        $tags = Tag::factory()->count(5)->create();

        foreach ($tags as $tag) {
            $this->media->tags()->attach($tag->id);
        }

        $this->assertEquals(5, $this->media->tags()->count());
    }

    /**
     * Test tag can be attached to multiple media.
     */
    public function test_tag_can_be_attached_to_multiple_media(): void
    {
        $media1 = Media::factory()->create(['user_id' => $this->user->id]);
        $media2 = Media::factory()->create(['user_id' => $this->user->id]);
        $media3 = Media::factory()->create(['user_id' => $this->user->id]);

        $media1->tags()->attach($this->tag->id);
        $media2->tags()->attach($this->tag->id);
        $media3->tags()->attach($this->tag->id);

        $this->assertEquals(3, $this->tag->media()->count());
    }
}
