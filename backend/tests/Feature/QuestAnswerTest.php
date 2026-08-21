<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Application des réponses aux quêtes (POST /quests/answer) : écriture directe
 * write-once, journalisation, autorisations et anti-course (409).
 */
class QuestAnswerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Person $self;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->self = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $this->user->forceFill(['person_id' => $this->self->id])->save();
    }

    private function answer(array $payload, ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->postJson('/quests/answer', $payload);
    }

    public function test_reponse_date_de_naissance_ecrit_le_champ_et_journalise_le_payload(): void
    {
        $person = Person::factory()->create(['birth_date' => null]);

        $response = $this->answer([
            'question_type' => 'birth_date',
            'subject_id' => $person->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '1952-03-14'],
        ]);

        $response->assertCreated()->assertJsonPath('completed_count', 1);
        $this->assertSame('1952-03-14', $person->fresh()->birth_date->toDateString());
        $this->assertDatabaseHas('quest_answers', [
            'user_id' => $this->user->id,
            'question_key' => "birth_date:{$person->id}",
            'answer_kind' => 'answered',
        ]);
    }

    public function test_reponse_sur_un_champ_deja_rempli_renvoie_409_sans_ecraser(): void
    {
        $person = Person::factory()->create(['birth_date' => '1950-01-01']);

        $response = $this->answer([
            'question_type' => 'birth_date',
            'subject_id' => $person->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '1999-12-31'],
        ]);

        $response->assertStatus(409)->assertJsonStructure(['message', 'next', 'completed_count']);
        $this->assertSame('1950-01-01', $person->fresh()->birth_date->toDateString());
        $this->assertDatabaseCount('quest_answers', 0);
    }

    public function test_reponse_mariage_cree_la_relation_avec_ids_tries_sans_doublon(): void
    {
        $wife = Person::factory()->female()->create();
        $husband = Person::factory()->male()->create();

        $response = $this->answer([
            'question_type' => 'marital_status',
            'subject_id' => $wife->id,
            'answer_kind' => 'answered',
            'payload' => ['spouse_id' => $husband->id, 'type' => 'spouse', 'year' => 1985],
        ]);

        $response->assertCreated();

        $ids = [$wife->id, $husband->id];
        sort($ids);
        $this->assertDatabaseHas('person_relationships', [
            'person1_id' => $ids[0],
            'person2_id' => $ids[1],
            'type' => 'spouse',
        ]);
        // L'année seule ne remplit PAS start_date (faux 1ᵉʳ janvier fêté par
        // le Dashboard) : elle ne vit que dans le payload journalisé.
        $this->assertNull(PersonRelationship::first()->start_date);

        // Rejouer la même réponse : le manque n'existe plus → 409, pas de doublon.
        $this->answer([
            'question_type' => 'marital_status',
            'subject_id' => $wife->id,
            'answer_kind' => 'answered',
            'payload' => ['spouse_id' => $husband->id],
        ])->assertStatus(409);

        $this->assertSame(1, PersonRelationship::count());
    }

    public function test_reponse_metier_cree_un_life_event_au_nom_du_repondant(): void
    {
        $person = Person::factory()->create();

        $this->answer([
            'question_type' => 'job',
            'subject_id' => $person->id,
            'answer_kind' => 'answered',
            'payload' => ['title' => 'Institutrice', 'year' => 1978],
        ])->assertCreated();

        $this->assertDatabaseHas('life_events', [
            'person_id' => $person->id,
            'user_id' => $this->user->id,
            'type' => 'job',
            'title' => 'Institutrice',
            'event_date' => '1978-01-01 00:00:00',
        ]);
    }

    public function test_reponse_visage_associe_le_visage_comme_match_face(): void
    {
        $media = Media::factory()->photo()->create(['user_id' => $this->user->id]);
        $face = $media->detectedFaces()->create([
            'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
            'status' => 'unmatched',
            'provider' => 'faceapi',
        ]);
        $person = Person::factory()->create();

        $this->answer([
            'question_type' => 'face_identify',
            'subject_id' => $face->id,
            'answer_kind' => 'answered',
            'payload' => ['person_id' => $person->id],
        ])->assertCreated();

        $face->refresh();
        $this->assertSame('matched', $face->status);
        $this->assertSame($person->id, $face->person_id);
        $this->assertTrue($media->people()->whereKey($person->id)->exists());
    }

    public function test_pas_un_visage_passe_le_statut_a_dismissed(): void
    {
        $media = Media::factory()->photo()->create(['user_id' => $this->user->id]);
        $face = $media->detectedFaces()->create([
            'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
            'status' => 'unmatched',
            'provider' => 'faceapi',
        ]);

        $this->answer([
            'question_type' => 'face_identify',
            'subject_id' => $face->id,
            'answer_kind' => 'no',
        ])->assertCreated();

        $this->assertSame('dismissed', $face->fresh()->status);
        $this->assertDatabaseHas('quest_answers', [
            'question_key' => "face_identify:{$face->id}",
            'answer_kind' => 'no',
        ]);
    }

    public function test_non_owner_ne_peut_pas_dater_un_media(): void
    {
        $media = Media::factory()->photo()->create(['taken_at' => null]);

        $this->answer([
            'question_type' => 'media_date',
            'subject_id' => $media->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '2001-06-15'],
        ])->assertForbidden();

        $this->assertNull($media->fresh()->taken_at);
    }

    public function test_geolocalisation_ecrit_les_metadonnees_du_media(): void
    {
        $media = Media::factory()->photo()->create(['user_id' => $this->user->id]);

        $this->answer([
            'question_type' => 'media_geo',
            'subject_id' => $media->id,
            'answer_kind' => 'answered',
            'payload' => ['latitude' => 48.6361, 'longitude' => -2.0253],
        ])->assertCreated();

        $this->assertDatabaseHas('media_metadata', [
            'media_id' => $media->id,
            'latitude' => 48.6361,
            'longitude' => -2.0253,
        ]);
    }

    public function test_payload_invalide_renvoie_422(): void
    {
        $person = Person::factory()->create(['birth_date' => null]);

        // Valeur manquante.
        $this->answer([
            'question_type' => 'birth_date',
            'subject_id' => $person->id,
            'answer_kind' => 'answered',
            'payload' => [],
        ])->assertStatus(422);

        // Latitude hors bornes.
        $media = Media::factory()->photo()->create(['user_id' => $this->user->id]);
        $this->answer([
            'question_type' => 'media_geo',
            'subject_id' => $media->id,
            'answer_kind' => 'answered',
            'payload' => ['latitude' => 200, 'longitude' => 3],
        ])->assertStatus(422);

        // Métier sans année (event_date obligatoire en base).
        $this->answer([
            'question_type' => 'job',
            'subject_id' => $person->id,
            'answer_kind' => 'answered',
            'payload' => ['title' => 'Boulanger'],
        ])->assertStatus(422);

        // « Non » sur un type qui ne l'accepte pas.
        $this->answer([
            'question_type' => 'birth_date',
            'subject_id' => $person->id,
            'answer_kind' => 'no',
        ])->assertStatus(422);

        $this->assertDatabaseCount('quest_answers', 0);
    }

    public function test_le_compteur_ne_compte_que_les_reponses_answered(): void
    {
        $person = Person::factory()->create(['birth_date' => null, 'birth_place' => null]);

        $this->answer([
            'question_type' => 'birth_date',
            'subject_id' => $person->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '1960-05-05'],
        ])->assertCreated();

        $this->answer([
            'question_type' => 'birth_place',
            'subject_id' => $person->id,
            'answer_kind' => 'skipped',
        ])->assertCreated();

        $this->actingAs($this->user)->getJson('/quests/next')
            ->assertOk()
            ->assertJsonPath('completed_count', 1);
    }

    public function test_repondre_renvoie_la_question_suivante(): void
    {
        // Deux manques garantis sur la même fiche proche.
        $father = Person::factory()->male()->create([
            'user_id' => $this->user->id,
            'birth_date' => null,
            'birth_place' => null,
        ]);
        $this->self->update(['father_id' => $father->id]);

        $response = $this->answer([
            'question_type' => 'birth_date',
            'subject_id' => $father->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '1948-02-02'],
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('next.type'));
        $this->assertNotNull($response->json('next.prompt'));
    }
}
