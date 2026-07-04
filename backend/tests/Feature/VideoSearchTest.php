<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('public');
    }

    /**
     * Filtre par durée minimale.
     */
    public function test_can_filter_videos_by_duration_min(): void
    {
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 30]);   // 30s
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 120]);  // 2min
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 3600]); // 1h

        $response = $this->actingAs($this->user)
            ->getJson('/media?type=video&duration_min=60');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertGreaterThanOrEqual(60, $item['duration']);
        }
    }

    /**
     * Filtre par durée maximale.
     */
    public function test_can_filter_videos_by_duration_max(): void
    {
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 30]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 120]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 3600]);

        $response = $this->actingAs($this->user)
            ->getJson('/media?type=video&duration_max=120');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertLessThanOrEqual(120, $item['duration']);
        }
    }

    /**
     * Filtre par durée min ET max.
     */
    public function test_can_filter_videos_by_duration_range(): void
    {
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 30]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 120]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 3600]);

        $response = $this->actingAs($this->user)
            ->getJson('/media?type=video&duration_min=60&duration_max=300');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(120, $data[0]['duration']);
    }

    /**
     * Filtre par résolution 1080p.
     */
    public function test_can_filter_videos_by_resolution_1080p(): void
    {
        Media::factory()->video()->create(['user_id' => $this->user->id, 'height' => 480]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'height' => 720]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'height' => 1080]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'height' => 2160]);

        $response = $this->actingAs($this->user)
            ->getJson('/media?type=video&resolution=1080p');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data); // 1080p and 4K
        foreach ($data as $item) {
            $this->assertGreaterThanOrEqual(1080, $item['height']);
        }
    }

    /**
     * Filtre par résolution 4K.
     */
    public function test_can_filter_videos_by_resolution_4k(): void
    {
        Media::factory()->video()->create(['user_id' => $this->user->id, 'height' => 1080]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'height' => 2160]);

        $response = $this->actingAs($this->user)
            ->getJson('/media?type=video&resolution=4k');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals(2160, $data[0]['height']);
    }

    /**
     * Filtre par codec vidéo.
     */
    public function test_can_filter_videos_by_codec(): void
    {
        Media::factory()->video()->create(['user_id' => $this->user->id, 'video_codec' => 'h264']);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'video_codec' => 'h264']);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'video_codec' => 'hevc']);

        $response = $this->actingAs($this->user)
            ->getJson('/media?type=video&video_codec=h264');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertEquals('h264', $item['video_codec']);
        }
    }

    /**
     * Le filtre duration_min + type=video ne retourne que les vidéos correspondantes.
     */
    public function test_video_duration_filter_combined_with_type_returns_only_videos(): void
    {
        Media::factory()->photo()->count(3)->create(['user_id' => $this->user->id]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 30]);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'duration' => 180]);

        $response = $this->actingAs($this->user)
            ->getJson('/media?type=video&duration_min=60');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('video', $data[0]['type']);
        $this->assertGreaterThanOrEqual(60, $data[0]['duration']);
    }

    /**
     * La page index retourne la prop availableCodecs.
     */
    public function test_index_returns_available_codecs(): void
    {
        Media::factory()->video()->create(['user_id' => $this->user->id, 'video_codec' => 'h264']);
        Media::factory()->video()->create(['user_id' => $this->user->id, 'video_codec' => 'hevc']);

        $response = $this->actingAs($this->user)->get('/media');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->has('availableCodecs')
        );
    }
}
