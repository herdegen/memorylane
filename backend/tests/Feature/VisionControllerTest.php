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

    // --- Store faces (face-api.js, client-side) ---

    private function embedding(float $seed): array
    {
        $vec = array_fill(0, 128, 0.0);
        $vec[0] = $seed;

        return $vec;
    }

    public function test_store_faces_wipes_and_recreates_with_embedding(): void
    {
        // Un ancien visage (autre provider) doit être remplacé.
        DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 1, 'height' => 1],
            'provider' => 'google',
            'status' => 'unmatched',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/vision/media/{$this->media->id}/faces", [
                'faces' => [
                    [
                        'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
                        'confidence' => 0.9,
                        'embedding' => $this->embedding(0.1),
                    ],
                    [
                        'bounding_box' => ['x' => 50, 'y' => 55, 'width' => 10, 'height' => 12],
                        'confidence' => 0.8,
                        'embedding' => $this->embedding(0.2),
                    ],
                ],
            ]);

        $response->assertOk();
        $response->assertJsonFragment(['status' => 'completed', 'faces_count' => 2]);

        $faces = DetectedFace::where('media_id', $this->media->id)->get();
        $this->assertCount(2, $faces);
        $this->assertEquals('faceapi', $faces->first()->provider);
        $this->assertCount(128, $faces->first()->embedding);

        $this->media->refresh();
        $this->assertEquals('completed', $this->media->metadata->vision_status);
        $this->assertEquals('faceapi', $this->media->metadata->vision_provider);
        $this->assertEquals(2, $this->media->metadata->vision_faces_count);
    }

    public function test_store_faces_accepts_empty_set(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/vision/media/{$this->media->id}/faces", ['faces' => []]);

        $response->assertOk();
        $response->assertJsonFragment(['faces_count' => 0]);
        $this->media->refresh();
        $this->assertEquals('completed', $this->media->metadata->vision_status);
    }

    public function test_store_faces_rejects_bad_embedding_size(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson("/vision/media/{$this->media->id}/faces", [
                'faces' => [
                    [
                        'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
                        'embedding' => [0.1, 0.2, 0.3],
                    ],
                ],
            ]);

        $response->assertUnprocessable();
    }

    public function test_store_faces_rejects_non_photo(): void
    {
        $video = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'video']);

        $this->actingAs($this->user)
            ->postJson("/vision/media/{$video->id}/faces", ['faces' => []])
            ->assertUnprocessable();
    }

    public function test_cannot_store_faces_on_other_users_media(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson("/vision/media/{$this->media->id}/faces", ['faces' => []])
            ->assertForbidden();
    }

    // --- Reset face ---

    public function test_reset_face_detaches_person(): void
    {
        $person = Person::create(['user_id' => $this->user->id, 'name' => 'Jane']);

        $face = DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'provider' => 'faceapi',
            'status' => 'matched',
            'person_id' => $person->id,
        ]);
        $this->media->people()->attach($person->id);

        $response = $this->actingAs($this->user)
            ->postJson("/vision/faces/{$face->id}/reset");

        $response->assertOk();

        $face->refresh();
        $this->assertNull($face->person_id);
        $this->assertEquals('unmatched', $face->status);
        $this->assertFalse($this->media->people()->where('person_id', $person->id)->exists());
    }

    // --- Suggest ---

    public function test_suggest_returns_nearest_labelled_person(): void
    {
        $person = Person::create(['user_id' => $this->user->id, 'name' => 'Alice']);

        // Un visage déjà labellisé (sur un autre média du même user).
        $other = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo']);
        DetectedFace::create([
            'media_id' => $other->id,
            'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 1, 'height' => 1],
            'provider' => 'faceapi',
            'status' => 'matched',
            'person_id' => $person->id,
            'embedding' => $this->embedding(0.10),
        ]);

        // Le visage cible, descripteur très proche.
        $target = DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 2, 'y' => 2, 'width' => 2, 'height' => 2],
            'provider' => 'faceapi',
            'status' => 'unmatched',
            'embedding' => $this->embedding(0.11),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/vision/faces/{$target->id}/suggest");

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Alice']);
        $data = $response->json('suggestions');
        $this->assertNotEmpty($data);
        $this->assertLessThan(0.6, $data[0]['distance']);
    }

    public function test_suggest_returns_empty_for_distant_face(): void
    {
        $person = Person::create(['user_id' => $this->user->id, 'name' => 'Bob']);

        $other = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo']);
        DetectedFace::create([
            'media_id' => $other->id,
            'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 1, 'height' => 1],
            'provider' => 'faceapi',
            'status' => 'matched',
            'person_id' => $person->id,
            'embedding' => $this->embedding(0.1),
        ]);

        // Descripteur très éloigné (distance ≈ 5 > seuil 0.6).
        $target = DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 2, 'y' => 2, 'width' => 2, 'height' => 2],
            'provider' => 'faceapi',
            'status' => 'unmatched',
            'embedding' => $this->embedding(5.0),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/vision/faces/{$target->id}/suggest");

        $response->assertOk();
        $this->assertEmpty($response->json('suggestions'));
    }

    // --- Pending ---

    public function test_pending_lists_unanalyzed_photos(): void
    {
        // Une photo déjà analysée → exclue.
        MediaMetadata::create([
            'media_id' => $this->media->id,
            'vision_status' => 'completed',
        ]);
        // Une photo non analysée → incluse.
        $fresh = Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo']);

        $response = $this->actingAs($this->user)->getJson('/vision/pending');

        $response->assertOk();
        $ids = $response->json('media_ids');
        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($this->media->id, $ids);
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
