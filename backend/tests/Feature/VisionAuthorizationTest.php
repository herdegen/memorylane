<?php

namespace Tests\Feature;

use App\Models\DetectedFace;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Autorisations Vision (phase 2c) : l'identification des visages est
 * collaborative — ouverte à quiconque peut VOIR le média (propriétaire,
 * album partagé, foyer) — tandis que la re-détection destructive
 * (storeFaces/reanalyze) reste réservée au propriétaire. Un compte sans
 * accès au média reste en 403 partout.
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

    /** Crée un foyer owner+membre et y partage le média du setUp. */
    private function householdMember(): User
    {
        $member = User::factory()->create();
        $household = Household::factory()->create(['created_by' => $this->owner->id]);
        $household->members()->attach([$this->owner->id, $member->id]);
        $household->media()->attach($this->media->id, ['added_by' => $this->owner->id]);

        return $member;
    }

    /**
     * Un membre du cercle voit les visages et les suggestions, et peut aussi
     * les identifier depuis la fiche photo (édition partagée, journalisée).
     */
    public function test_membre_du_foyer_identifie_et_c_est_journalise(): void
    {
        $member = $this->householdMember();
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        // Photo (l'ajout manuel n'existe que sur une photo ou une vidéo), sans date :
        // le contrôle naissance/date de la photo ne dépend pas du hasard des fabriques.
        $this->media->update(['type' => 'photo', 'taken_at' => null]);

        $this->actingAs($member)->getJson("/vision/media/{$this->media->id}/faces")->assertOk();
        $this->actingAs($member)->getJson("/vision/faces/{$this->face->id}/suggest")->assertOk();

        $this->actingAs($member)
            ->postJson("/vision/faces/{$this->face->id}/match", ['person_id' => $person->id])
            ->assertOk();
        $this->actingAs($member)
            ->postJson("/vision/media/{$this->media->id}/faces/add", ['bounding_box' => ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5]])
            ->assertCreated();
        $this->assertSame('matched', $this->face->fresh()->status);
        $this->assertSame(2, \App\Models\MediaChange::where('media_id', $this->media->id)->count());
    }

    public function test_proprietaire_et_admin_identifient(): void
    {
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        $admin = User::factory()->admin()->create();

        $this->actingAs($this->owner)
            ->postJson("/vision/faces/{$this->face->id}/match", ['person_id' => $person->id])
            ->assertOk();
        $this->actingAs($admin)->postJson("/vision/faces/{$this->face->id}/reset")->assertOk();
        $this->assertEquals('rejected', $this->face->fresh()->status);
    }

    public function test_re_detection_reste_reservee_au_proprietaire(): void
    {
        $member = $this->householdMember();

        // Wipe + recreate : destructif, propriétaire seul.
        $this->actingAs($member)
            ->postJson("/vision/media/{$this->media->id}/faces", ['faces' => []])
            ->assertStatus(403);

        $this->assertNotNull($this->face->fresh());
    }
}
