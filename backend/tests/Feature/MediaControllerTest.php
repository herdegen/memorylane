<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaControllerTest extends TestCase
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

        // Fake storage for file uploads
        Storage::fake('public');
    }

    /**
     * Test listing media.
     */
    public function test_can_list_media(): void
    {
        // Create some media
        Media::factory()->count(5)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->get('/media');

        $response->assertStatus(200);
    }

    /**
     * Test filtering media by type.
     */
    public function test_can_filter_media_by_type(): void
    {
        // Create different types of media
        Media::factory()->photo()->count(3)->create(['user_id' => $this->user->id]);
        Media::factory()->video()->count(2)->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->get('/media?type=photo');

        $response->assertStatus(200);
        // The response contains Inertia props with paginated media
    }

    /**
     * Test searching media.
     */
    public function test_can_search_media(): void
    {
        // Create media with specific names
        Media::factory()->create([
            'user_id' => $this->user->id,
            'original_name' => 'vacation-photo.jpg',
        ]);
        Media::factory()->create([
            'user_id' => $this->user->id,
            'original_name' => 'work-document.pdf',
        ]);

        $response = $this->actingAs($this->user)->get('/media?search=vacation');

        $response->assertStatus(200);
    }

    /**
     * Test showing a single media.
     */
    public function test_can_show_media(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->get("/media/{$media->id}");

        $response->assertStatus(200);
    }

    /**
     * Test deleting media.
     */
    public function test_can_delete_media(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->deleteJson("/media/{$media->id}");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Media deleted successfully']);

        // Check soft delete
        $this->assertSoftDeleted('media', [
            'id' => $media->id,
        ]);
    }

    /**
     * Test uploading a photo.
     */
    public function test_can_upload_photo(): void
    {
        $file = UploadedFile::fake()->image('test-photo.jpg', 1920, 1080);

        $response = $this->actingAs($this->user)->postJson('/media', [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'media' => ['id', 'type', 'original_name', 'mime_type'],
            ]);

        $this->assertDatabaseHas('media', [
            'user_id' => $this->user->id,
            'type' => 'photo',
            'mime_type' => 'image/jpeg',
        ]);
    }

    /**
     * Test filtering media by tags.
     */
    public function test_can_filter_media_by_tags(): void
    {
        // Create tags
        $tag1 = Tag::factory()->create(['name' => 'Vacation']);
        $tag2 = Tag::factory()->create(['name' => 'Family']);

        // Create media with tags
        $media1 = Media::factory()->create(['user_id' => $this->user->id]);
        $media1->tags()->attach($tag1->id);

        $media2 = Media::factory()->create(['user_id' => $this->user->id]);
        $media2->tags()->attach($tag2->id);

        $media3 = Media::factory()->create(['user_id' => $this->user->id]);
        $media3->tags()->attach([$tag1->id, $tag2->id]);

        $response = $this->actingAs($this->user)->get("/media?tags[]={$tag1->id}");

        $response->assertStatus(200);
        // Should return media1 and media3 (both have tag1)
    }

    /**
     * Test that upload validates file types.
     */
    public function test_upload_validates_file_types(): void
    {
        $file = UploadedFile::fake()->create('malicious.exe', 1000);

        $response = $this->actingAs($this->user)->postJson('/media', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * Test that upload validates file size.
     */
    public function test_upload_validates_file_size(): void
    {
        // Create a file larger than max allowed (2GB limit)
        $file = UploadedFile::fake()->create('huge-file.jpg', 2100000); // > 2GB

        $response = $this->actingAs($this->user)->postJson('/media', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }
}
