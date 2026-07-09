<?php

namespace Tests\Feature;

use App\Filament\Resources\FaceRecognitionResource\Pages\ListFaceRecognition;
use App\Jobs\AnalyzeMediaWithVision;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Vision\FaceMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class FaceRecognitionAdminTest extends TestCase
{
    use RefreshDatabase;

    private function embedding(float $value): array
    {
        return array_fill(0, 128, $value);
    }

    /**
     * Crée une photo avec un visage labellisé (person) pour le propriétaire, afin
     * d'alimenter la banque de descripteurs de l'auto-association.
     */
    private function labelledFace(User $owner, array $embedding): Person
    {
        $person = Person::factory()->create(['user_id' => $owner->id]);
        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $media->detectedFaces()->create([
            'person_id' => $person->id,
            'bounding_box' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.2],
            'embedding' => $embedding,
            'provider' => 'faceapi',
            'status' => 'matched',
        ]);

        return $person;
    }

    private function unmatchedFace(Media $media, array $embedding): DetectedFace
    {
        return $media->detectedFaces()->create([
            'bounding_box' => ['x' => 0.5, 'y' => 0.5, 'width' => 0.2, 'height' => 0.2],
            'embedding' => $embedding,
            'provider' => 'faceapi',
            'status' => 'unmatched',
        ]);
    }

    public function test_face_matcher_associe_le_plus_proche_voisin_du_proprietaire(): void
    {
        $owner = User::factory()->create();
        $person = $this->labelledFace($owner, $this->embedding(0.1));

        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $face = $this->unmatchedFace($media, $this->embedding(0.1));

        $result = app(FaceMatcher::class)->autoMatch($face, $owner->id);

        $this->assertNotNull($result);
        $this->assertSame($person->id, $result['person']['id']);
        $this->assertSame('matched', $face->fresh()->status);
        $this->assertTrue($media->people()->where('people.id', $person->id)->exists());
    }

    public function test_face_matcher_ne_traverse_pas_les_proprietaires(): void
    {
        $owner = User::factory()->create();
        $stranger = User::factory()->create();

        // Seul un ÉTRANGER possède un visage labellisé avec ce descripteur.
        $this->labelledFace($stranger, $this->embedding(0.1));

        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $face = $this->unmatchedFace($media, $this->embedding(0.1));

        $result = app(FaceMatcher::class)->autoMatch($face, $owner->id);

        $this->assertNull($result);
        $this->assertSame('unmatched', $face->fresh()->status);
    }

    public function test_admin_peut_voir_la_page_reconnaissance(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/face-recognitions');

        $response->assertStatus(200);
    }

    public function test_relancer_la_detection_reinitialise_et_redispatch(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $media = Media::factory()->photo()->create(['user_id' => $admin->id]);
        $this->unmatchedFace($media, $this->embedding(0.2));
        $media->metadata()->create(['vision_status' => 'completed', 'vision_faces_count' => 1]);

        Livewire::actingAs($admin)
            ->test(ListFaceRecognition::class)
            ->callTableAction('reanalyzeDetection', $media);

        $this->assertSame(0, $media->detectedFaces()->count());
        $this->assertSame('pending', $media->metadata->fresh()->vision_status);
        Queue::assertPushed(AnalyzeMediaWithVision::class);
    }

    public function test_relancer_auto_association_matche_les_visages(): void
    {
        $admin = User::factory()->admin()->create();
        $person = $this->labelledFace($admin, $this->embedding(0.3));

        $media = Media::factory()->photo()->create(['user_id' => $admin->id]);
        $face = $this->unmatchedFace($media, $this->embedding(0.3));

        Livewire::actingAs($admin)
            ->test(ListFaceRecognition::class)
            ->callTableAction('autoMatch', $media);

        $this->assertSame('matched', $face->fresh()->status);
        $this->assertSame($person->id, $face->fresh()->person_id);
    }
}
