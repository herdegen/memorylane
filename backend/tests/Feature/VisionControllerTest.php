<?php

namespace Tests\Feature;

use App\Contracts\VisionServiceInterface;
use App\Jobs\AnalyzeMediaWithVision;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\MediaMetadata;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VisionControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->media = Media::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'photo',
        ]);
    }

    // --- Faces endpoint ---

    public function test_can_get_detected_faces_for_own_media(): void
    {
        $face = DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'confidence' => 0.95,
            'provider' => 'google',
            'status' => 'unmatched',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/vision/media/{$this->media->id}/faces");

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['status' => 'unmatched']);
    }

    public function test_dismissed_faces_are_not_returned(): void
    {
        DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'confidence' => 0.95,
            'provider' => 'google',
            'status' => 'dismissed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/vision/media/{$this->media->id}/faces");

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_cannot_get_faces_for_other_users_media(): void
    {
        $otherUser = User::factory()->create();

        $response = $this->actingAs($otherUser)
            ->getJson("/vision/media/{$this->media->id}/faces");

        $response->assertForbidden();
    }

    // --- Match face ---

    public function test_can_match_face_to_person(): void
    {
        $face = DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'confidence' => 0.95,
            'provider' => 'google',
            'status' => 'unmatched',
        ]);

        $person = Person::create([
            'user_id' => $this->user->id,
            'name' => 'John Doe',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/vision/faces/{$face->id}/match", [
                'person_id' => $person->id,
            ]);

        $response->assertOk();

        $face->refresh();
        $this->assertEquals('matched', $face->status);
        $this->assertEquals($person->id, $face->person_id);

        // Check media_person pivot was created
        $this->assertTrue($this->media->people()->where('person_id', $person->id)->exists());
    }

    public function test_match_face_requires_valid_person_id(): void
    {
        $face = DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'confidence' => 0.95,
            'provider' => 'google',
            'status' => 'unmatched',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/vision/faces/{$face->id}/match", [
                'person_id' => 'invalid-uuid',
            ]);

        $response->assertUnprocessable();
    }

    // --- Dismiss face ---

    public function test_can_dismiss_face(): void
    {
        $face = DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'confidence' => 0.95,
            'provider' => 'google',
            'status' => 'unmatched',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/vision/faces/{$face->id}/dismiss");

        $response->assertOk();

        $face->refresh();
        $this->assertEquals('dismissed', $face->status);
    }

    // --- Labels endpoint ---

    public function test_can_get_vision_labels(): void
    {
        MediaMetadata::create([
            'media_id' => $this->media->id,
            'vision_labels' => [
                ['name' => 'Nature', 'score' => 0.95, 'topicality' => 0.9],
                ['name' => 'Family', 'score' => 0.88, 'topicality' => 0.8],
            ],
            'vision_status' => 'completed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/vision/media/{$this->media->id}/labels");

        $response->assertOk();
        $response->assertJsonCount(2, 'labels');
        $response->assertJsonFragment(['status' => 'completed']);
    }

    // --- Re-analyze ---

    public function test_can_reanalyze_media(): void
    {
        Queue::fake();

        DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'confidence' => 0.95,
            'provider' => 'google',
            'status' => 'unmatched',
        ]);

        MediaMetadata::create([
            'media_id' => $this->media->id,
            'vision_status' => 'completed',
            'vision_faces_count' => 1,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/vision/media/{$this->media->id}/analyze");

        $response->assertOk();

        // Old faces should be deleted
        $this->assertEquals(0, DetectedFace::where('media_id', $this->media->id)->count());

        // Status should be reset
        $this->media->refresh();
        $this->assertEquals('pending', $this->media->metadata->vision_status);

        // Job should be dispatched
        Queue::assertPushed(AnalyzeMediaWithVision::class);
    }

    public function test_cannot_reanalyze_non_photo(): void
    {
        $videoMedia = Media::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'video',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/vision/media/{$videoMedia->id}/analyze");

        $response->assertUnprocessable();
    }

    // --- Status endpoint ---

    public function test_can_get_vision_status(): void
    {
        MediaMetadata::create([
            'media_id' => $this->media->id,
            'vision_status' => 'processing',
            'vision_provider' => 'google',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/vision/media/{$this->media->id}/status");

        $response->assertOk();
        $response->assertJsonFragment([
            'status' => 'processing',
            'provider' => 'google',
        ]);
    }

    public function test_status_returns_null_when_no_metadata(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson("/vision/media/{$this->media->id}/status");

        $response->assertOk();
        $response->assertJsonFragment(['status' => null]);
    }

    // --- Authorization ---

    public function test_cannot_access_vision_endpoints_unauthenticated(): void
    {
        $this->getJson("/vision/media/{$this->media->id}/faces")
            ->assertUnauthorized();

        $this->getJson("/vision/media/{$this->media->id}/status")
            ->assertUnauthorized();

        $this->postJson("/vision/media/{$this->media->id}/analyze")
            ->assertUnauthorized();
    }
}
