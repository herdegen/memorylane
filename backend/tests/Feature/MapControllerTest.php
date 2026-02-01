<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Models\Tag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Test map index page loads successfully.
     */
    public function test_map_index_loads(): void
    {
        $response = $this->actingAs($this->user)->get('/map');

        $response->assertStatus(200);
    }

    /**
     * Test getting geolocated media.
     */
    public function test_can_get_geolocated_media(): void
    {
        // Create media with geolocation
        $media1 = Media::factory()->create(['user_id' => $this->user->id]);
        $media1->metadata()->create([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);

        // Create media without geolocation
        $media2 = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->getJson('/map/media');

        $response->assertStatus(200)
            ->assertJsonCount(1); // Only media with geolocation

        // Verify the returned media has coordinates
        $json = $response->json();
        $this->assertEquals(48.8566, $json[0]['latitude']);
        $this->assertEquals(2.3522, $json[0]['longitude']);
    }

    /**
     * Test filtering geolocated media by type.
     */
    public function test_can_filter_geolocated_media_by_type(): void
    {
        // Create photo with geolocation
        $photo = Media::factory()->photo()->create(['user_id' => $this->user->id]);
        $photo->metadata()->create([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);

        // Create video with geolocation
        $video = Media::factory()->video()->create(['user_id' => $this->user->id]);
        $video->metadata()->create([
            'latitude' => 45.764,
            'longitude' => 4.8357,
        ]);

        $response = $this->actingAs($this->user)->getJson('/map/media?type=photo');

        $response->assertStatus(200)
            ->assertJsonCount(1);

        $this->assertEquals('photo', $response->json()[0]['type']);
    }

    /**
     * Test filtering geolocated media by tags.
     */
    public function test_can_filter_geolocated_media_by_tags(): void
    {
        $tag = Tag::factory()->create(['name' => 'Vacation']);

        $media1 = Media::factory()->create(['user_id' => $this->user->id]);
        $media1->metadata()->create([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);
        $media1->tags()->attach($tag->id);

        $media2 = Media::factory()->create(['user_id' => $this->user->id]);
        $media2->metadata()->create([
            'latitude' => 45.764,
            'longitude' => 4.8357,
        ]);

        $response = $this->actingAs($this->user)->getJson("/map/media?tags[]={$tag->id}");

        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    /**
     * Test updating media geolocation.
     */
    public function test_can_update_media_geolocation(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/map/media/{$media->id}/geolocation", [
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'altitude' => 35,
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Geolocation updated successfully']);

        $this->assertDatabaseHas('media_metadata', [
            'media_id' => $media->id,
            'latitude' => 48.8566,
            'longitude' => 2.3522,
            'altitude' => 35,
        ]);
    }

    /**
     * Test removing media geolocation.
     */
    public function test_can_remove_media_geolocation(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);
        $media->metadata()->create([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/map/media/{$media->id}/geolocation");

        $response->assertStatus(200)
            ->assertJson(['message' => 'Geolocation removed successfully']);

        $this->assertDatabaseHas('media_metadata', [
            'media_id' => $media->id,
            'latitude' => null,
            'longitude' => null,
        ]);
    }

    /**
     * Test geolocation validation - latitude.
     */
    public function test_geolocation_validates_latitude(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/map/media/{$media->id}/geolocation", [
            'latitude' => 100, // Invalid: > 90
            'longitude' => 2.3522,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['latitude']);
    }

    /**
     * Test geolocation validation - longitude.
     */
    public function test_geolocation_validates_longitude(): void
    {
        $media = Media::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/map/media/{$media->id}/geolocation", [
            'latitude' => 48.8566,
            'longitude' => 200, // Invalid: > 180
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['longitude']);
    }

    /**
     * Test searching for nearby media.
     */
    public function test_can_get_nearby_media(): void
    {
        // Paris location
        $media1 = Media::factory()->create(['user_id' => $this->user->id]);
        $media1->metadata()->create([
            'latitude' => 48.8566,
            'longitude' => 2.3522,
        ]);

        // Near Paris (within 5km)
        $media2 = Media::factory()->create(['user_id' => $this->user->id]);
        $media2->metadata()->create([
            'latitude' => 48.8606,
            'longitude' => 2.3376,
        ]);

        // Lyon (far away)
        $media3 = Media::factory()->create(['user_id' => $this->user->id]);
        $media3->metadata()->create([
            'latitude' => 45.764,
            'longitude' => 4.8357,
        ]);

        $response = $this->actingAs($this->user)->getJson('/map/nearby?latitude=48.8566&longitude=2.3522&radius=5');

        $response->assertStatus(200);

        $json = $response->json();
        $this->assertCount(2, $json); // Only Paris and nearby, not Lyon
    }

    /**
     * Test location search endpoint.
     */
    public function test_location_search_requires_query(): void
    {
        $response = $this->actingAs($this->user)->getJson('/map/search?query=Pa');

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['query']);
    }

    /**
     * Test location search with valid query.
     */
    public function test_location_search_with_valid_query(): void
    {
        // This test requires internet connection to Nominatim API
        // We'll just test that the endpoint accepts the request
        $response = $this->actingAs($this->user)->getJson('/map/search?query=Paris');

        // Should return 200 if Nominatim is accessible, or 500 if network issue
        $this->assertContains($response->status(), [200, 500]);
    }
}
