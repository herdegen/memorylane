<?php

namespace Tests\Feature;

use App\Filament\Resources\FaceRecognitionResource\Pages\ListFaceRecognition;
use App\Filament\Resources\PersonResource\Pages\EditPerson;
use App\Filament\Resources\PersonResource\RelationManagers\IdentifiedPhotosRelationManager;
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

    /**
     * Cœur de l'issue #30 : une désassociation doit être « collante » — le
     * visage passe en `rejected`, quitte le jeu de références, et n'est PAS
     * ré-associé par l'auto-association même si son voisin le plus proche est
     * toujours la personne dont on vient de le retirer.
     */
    public function test_desassociation_est_collante_et_bloque_le_re_match(): void
    {
        $owner = User::factory()->create();
        // Référence labellisée de la personne (voisin le plus proche).
        $person = $this->labelledFace($owner, $this->embedding(0.1));

        // Faux positif : un visage matché sur cette personne.
        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $face = $media->detectedFaces()->create([
            'person_id' => $person->id,
            'bounding_box' => ['x' => 0.5, 'y' => 0.5, 'width' => 0.2, 'height' => 0.2],
            'embedding' => $this->embedding(0.1),
            'provider' => 'faceapi',
            'status' => 'matched',
        ]);
        $media->people()->attach($person->id);

        $matcher = app(FaceMatcher::class);
        $matcher->disassociate($face->load('media'));

        $face->refresh();
        $this->assertNull($face->person_id);
        $this->assertSame('rejected', $face->status);
        $this->assertFalse($media->people()->where('people.id', $person->id)->exists());

        // L'auto-association ne doit PAS le récupérer (statut rejected exclu).
        $this->assertNull($matcher->autoMatch($face, $owner->id));
        $this->assertSame('rejected', $face->fresh()->status);
    }

    /**
     * Le pivot media_person est per-média : désassocier un visage ne doit
     * retirer la personne du média que s'il ne lui reste plus aucun visage.
     */
    public function test_desassociation_conserve_le_tag_tant_qu_un_visage_reste(): void
    {
        $owner = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $owner->id]);
        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);

        $faceA = $media->detectedFaces()->create([
            'person_id' => $person->id,
            'bounding_box' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.2],
            'provider' => 'faceapi',
            'status' => 'matched',
        ]);
        $faceB = $media->detectedFaces()->create([
            'person_id' => $person->id,
            'bounding_box' => ['x' => 0.6, 'y' => 0.6, 'width' => 0.2, 'height' => 0.2],
            'provider' => 'faceapi',
            'status' => 'matched',
        ]);
        $media->people()->attach($person->id);

        $matcher = app(FaceMatcher::class);

        $matcher->disassociate($faceA->load('media'));
        // Il reste faceB : la personne demeure taguée sur le média.
        $this->assertTrue($media->people()->where('people.id', $person->id)->exists());

        $matcher->disassociate($faceB->load('media'));
        // Plus aucun visage : la personne est retirée du média.
        $this->assertFalse($media->people()->where('people.id', $person->id)->exists());
    }

    /**
     * En auto-association, une personne ne peut pas être associée à deux visages
     * du même média (hors montage/reflet). Le second visage reste non identifié.
     */
    public function test_auto_match_n_associe_pas_deux_fois_la_meme_personne(): void
    {
        $owner = User::factory()->create();
        $person = $this->labelledFace($owner, $this->embedding(0.1));

        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $face1 = $this->unmatchedFace($media, $this->embedding(0.1));
        $face2 = $media->detectedFaces()->create([
            'bounding_box' => ['x' => 0.7, 'y' => 0.7, 'width' => 0.2, 'height' => 0.2],
            'embedding' => $this->embedding(0.1),
            'provider' => 'faceapi',
            'status' => 'unmatched',
        ]);

        $matcher = app(FaceMatcher::class);

        $this->assertNotNull($matcher->autoMatch($face1->load('media'), $owner->id));
        $this->assertSame($person->id, $face1->fresh()->person_id);

        // Même meilleur candidat, mais la personne est déjà sur le média.
        $this->assertNull($matcher->autoMatch($face2->load('media'), $owner->id));
        $this->assertNull($face2->fresh()->person_id);
        $this->assertSame('unmatched', $face2->fresh()->status);
    }

    /**
     * L'association MANUELLE reste permissive (montage/reflet) : la même personne
     * peut être associée à deux visages d'un même média.
     */
    public function test_association_manuelle_autorise_le_doublon(): void
    {
        $owner = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $owner->id]);
        $media = Media::factory()->photo()->create(['user_id' => $owner->id]);

        $face1 = $this->unmatchedFace($media, $this->embedding(0.2));
        $face2 = $media->detectedFaces()->create([
            'bounding_box' => ['x' => 0.7, 'y' => 0.7, 'width' => 0.2, 'height' => 0.2],
            'provider' => 'faceapi',
            'status' => 'unmatched',
        ]);

        $matcher = app(FaceMatcher::class);
        $matcher->applyMatch($face1->load('media'), $person->id);
        $matcher->applyMatch($face2->load('media'), $person->id);

        $this->assertSame($person->id, $face1->fresh()->person_id);
        $this->assertSame($person->id, $face2->fresh()->person_id);
    }

    /**
     * Le RelationManager « Photos identifiées » de la fiche personne rend bien
     * et son action groupée « Désassocier » applique la désassociation collante.
     */
    public function test_relation_manager_desassocie_les_photos_selectionnees(): void
    {
        $admin = User::factory()->admin()->create();
        $person = Person::factory()->create(['user_id' => $admin->id]);
        $media = Media::factory()->photo()->create(['user_id' => $admin->id]);
        $face = $media->detectedFaces()->create([
            'person_id' => $person->id,
            'bounding_box' => ['x' => 0.1, 'y' => 0.1, 'width' => 0.2, 'height' => 0.2],
            'provider' => 'faceapi',
            'status' => 'matched',
        ]);
        $media->people()->attach($person->id);

        Livewire::actingAs($admin)
            ->test(IdentifiedPhotosRelationManager::class, [
                'ownerRecord' => $person,
                'pageClass' => EditPerson::class,
            ])
            ->assertSuccessful()
            ->callTableBulkAction('disassociate', [$face]);

        $this->assertSame('rejected', $face->fresh()->status);
        $this->assertNull($face->fresh()->person_id);
        $this->assertFalse($media->people()->where('people.id', $person->id)->exists());
    }
}
