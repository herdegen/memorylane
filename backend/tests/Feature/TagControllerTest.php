<?php

namespace Tests\Feature;

use App\Models\Tag;
use App\Models\User;
use App\Models\Media;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test user
        $this->user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@memorylane.local',
        ]);
    }

    /**
     * Test creating a new tag.
     */
    public function test_can_create_a_tag(): void
    {
        $tagData = [
            'name' => 'Vacances',
            'color' => '#FF5733',
            'type' => 'event',
        ];

        $response = $this->postJson('/tags', $tagData);

        $response->assertStatus(201)
            ->assertJsonPath('tag.name', 'Vacances')
            ->assertJsonPath('tag.slug', 'vacances')
            ->assertJsonPath('tag.color', '#FF5733')
            ->assertJsonPath('tag.type', 'event');

        $this->assertDatabaseHas('tags', [
            'name' => 'Vacances',
            'slug' => 'vacances',
            'color' => '#FF5733',
        ]);
    }

    /**
     * Test that slug is auto-generated from name.
     */
    public function test_tag_slug_is_auto_generated(): void
    {
        $tagData = [
            'name' => 'Voyage en Famille',
            'color' => '#3498DB',
        ];

        $response = $this->postJson('/tags', $tagData);

        $response->assertStatus(201)
            ->assertJsonPath('tag.slug', 'voyage-en-famille');

        $this->assertDatabaseHas('tags', [
            'name' => 'Voyage en Famille',
            'slug' => 'voyage-en-famille',
        ]);
    }

    /**
     * Test listing all tags.
     */
    public function test_can_list_tags(): void
    {
        // Create some tags
        Tag::factory()->count(3)->create();

        $response = $this->getJson('/tags');

        $response->assertStatus(200)
            ->assertJsonStructure([
                '*' => ['id', 'name', 'slug', 'color', 'type', 'media_count'],
            ])
            ->assertJsonCount(3);
    }

    /**
     * Test updating a tag.
     */
    public function test_can_update_a_tag(): void
    {
        $tag = Tag::factory()->create([
            'name' => 'Old Name',
            'color' => '#000000',
        ]);

        $updateData = [
            'name' => 'New Name',
            'color' => '#FFFFFF',
        ];

        $response = $this->putJson("/tags/{$tag->id}", $updateData);

        $response->assertStatus(200)
            ->assertJsonPath('tag.name', 'New Name')
            ->assertJsonPath('tag.color', '#FFFFFF');

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
            'name' => 'New Name',
            'color' => '#FFFFFF',
        ]);
    }

    /**
     * Test deleting a tag.
     */
    public function test_can_delete_a_tag(): void
    {
        $tag = Tag::factory()->create();

        $response = $this->deleteJson("/tags/{$tag->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Tag deleted successfully']);

        $this->assertDatabaseMissing('tags', [
            'id' => $tag->id,
        ]);
    }

    /**
     * Test validation: name is required.
     */
    public function test_tag_name_is_required(): void
    {
        $response = $this->postJson('/tags', [
            'color' => '#FF5733',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    /**
     * Test validation: name must be unique.
     */
    public function test_tag_name_must_be_unique(): void
    {
        Tag::factory()->create(['name' => 'Duplicate']);

        $response = $this->postJson('/tags', [
            'name' => 'Duplicate',
            'color' => '#FF5733',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }
}
