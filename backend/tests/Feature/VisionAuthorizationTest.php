<?php

namespace Tests\Feature;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verrouille les 403 cross-user des endpoints Vision : tous les visages et
 * l'endpoint de streaming d'image sont réservés au propriétaire du média.
 */
class VisionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $intruder;

    protected Media $media;

    protected DetectedFace $face;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
        $this->media = Media::factory()->create(['user_id' => $this->owner->id]);
        $this->face = DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'confidence' => 0.95,
            'provider' => 'google',
            'status' => 'unmatched',
        ]);
    }

    public function test_face_actions_are_forbidden_for_non_owner(): void
    {
        $person = Person::factory()->create(['user_id' => $this->intruder->id]);

        $this->actingAs($this->intruder)
            ->postJson("/vision/faces/{$this->face->id}/match", ['person_id' => $person->id])
            ->assertStatus(403);

        $this->actingAs($this->intruder)
            ->postJson("/vision/faces/{$this->face->id}/dismiss")
            ->assertStatus(403);

        $this->actingAs($this->intruder)
            ->postJson("/vision/faces/{$this->face->id}/reset")
            ->assertStatus(403);

        $this->actingAs($this->intruder)
            ->getJson("/vision/faces/{$this->face->id}/suggest")
            ->assertStatus(403);

        $this->actingAs($this->intruder)
            ->postJson("/vision/faces/{$this->face->id}/auto-match")
            ->assertStatus(403);

        $this->assertEquals('unmatched', $this->face->fresh()->status);
    }

    public function test_image_streaming_endpoint_is_forbidden_for_non_owner(): void
    {
        $this->actingAs($this->intruder)
            ->get("/vision/media/{$this->media->id}/image")
            ->assertStatus(403);
    }

    public function test_add_face_is_forbidden_for_non_owner(): void
    {
        $this->actingAs($this->intruder)
            ->postJson("/vision/media/{$this->media->id}/faces/add", [
                'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5],
            ])
            ->assertStatus(403);
    }
}
