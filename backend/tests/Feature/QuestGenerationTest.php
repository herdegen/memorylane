<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\QuestAnswer;
use App\Models\User;
use App\Services\QuestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Génération des quêtes (gamification) : cercle proche, détection des manques,
 * règles d'extinction du journal quest_answers.
 */
class QuestGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Person $self;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->self = $this->completePerson(['user_id' => $this->user->id]);
        $this->user->forceFill(['person_id' => $this->self->id])->save();
    }

    /**
     * Fiche « sans manque personne » : tous les champs interrogeables remplis
     * (les questions parent restent possibles si father/mother_id sont null).
     */
    private function completePerson(array $attributes = []): Person
    {
        return Person::factory()->create(array_merge([
            'gender' => 'M',
            'birth_date' => now()->subYears(40),
            'birth_place' => 'Lille',
        ], $attributes));
    }

    /** Clés "type:subject_id" du lot de candidats (cache purgé avant). */
    private function candidateKeys(User $user): array
    {
        $service = app(QuestService::class);
        $service->forget($user);

        return array_map(fn ($c) => $c['type'].':'.$c['subject_id'], $service->candidates($user));
    }

    public function test_genere_une_question_pour_une_personne_du_cercle_proche(): void
    {
        $father = $this->completePerson(['user_id' => $this->user->id, 'birth_date' => null]);
        $this->self->update(['father_id' => $father->id]);

        $this->assertContains("birth_date:{$father->id}", $this->candidateKeys($this->user));

        $response = $this->actingAs($this->user)->getJson('/quests/next');
        $response->assertOk()
            ->assertJsonStructure(['question', 'completed_count']);
        $this->assertNotNull($response->json('question.type'));
        $this->assertNotNull($response->json('question.prompt'));
    }

    public function test_ignore_les_personnes_au_dela_de_la_distance_quatre(): void
    {
        // Chaîne d'ancêtres : self → p1 → p2 → p3 → p4 → p5 (distance 5).
        $previous = $this->self;
        $chain = [];
        foreach (range(1, 5) as $i) {
            $ancestor = $this->completePerson(['user_id' => $this->user->id]);
            $previous->update(['father_id' => $ancestor->id]);
            $chain[$i] = $ancestor;
            $previous = $ancestor;
        }

        $keys = implode(' ', $this->candidateKeys($this->user));

        $this->assertStringContainsString($chain[4]->id, $keys);
        $this->assertStringNotContainsString($chain[5]->id, $keys);
    }

    public function test_fallback_par_degre_quand_l_utilisateur_n_a_pas_de_fiche_moi(): void
    {
        $orphanUser = User::factory()->create();
        $person = $this->completePerson(['birth_date' => null]);

        $this->assertContains(
            "birth_date:{$person->id}",
            $this->candidateKeys($orphanUser),
        );
    }

    public function test_ne_genere_pas_de_question_sur_un_champ_deja_rempli(): void
    {
        $father = $this->completePerson(['user_id' => $this->user->id]);
        $this->self->update(['father_id' => $father->id]);

        $keys = $this->candidateKeys($this->user);

        $this->assertNotContains("birth_date:{$father->id}", $keys);
        $this->assertNotContains("birth_place:{$father->id}", $keys);
        $this->assertNotContains("gender:{$father->id}", $keys);
    }

    public function test_je_ne_sais_pas_masque_la_question_pour_cet_utilisateur_seulement(): void
    {
        $father = $this->completePerson(['user_id' => $this->user->id, 'birth_date' => null]);
        $this->self->update(['father_id' => $father->id]);

        QuestAnswer::create([
            'user_id' => $this->user->id,
            'question_type' => 'birth_date',
            'question_key' => "birth_date:{$father->id}",
            'subject_type' => Person::class,
            'subject_id' => $father->id,
            'answer_kind' => 'dont_know',
        ]);

        $otherUser = User::factory()->create();

        $this->assertNotContains("birth_date:{$father->id}", $this->candidateKeys($this->user));
        $this->assertContains("birth_date:{$father->id}", $this->candidateKeys($otherUser));
    }

    public function test_non_eteint_la_question_pour_tous_les_utilisateurs(): void
    {
        $uncle = $this->completePerson(['user_id' => $this->user->id]);
        $this->self->update(['father_id' => $uncle->id]);

        QuestAnswer::create([
            'user_id' => User::factory()->create()->id,
            'question_type' => 'marital_status',
            'question_key' => "marital_status:{$uncle->id}",
            'subject_type' => Person::class,
            'subject_id' => $uncle->id,
            'answer_kind' => 'no',
        ]);

        $this->assertNotContains("marital_status:{$uncle->id}", $this->candidateKeys($this->user));
    }

    public function test_passer_expire_apres_sept_jours(): void
    {
        $father = $this->completePerson(['user_id' => $this->user->id, 'birth_date' => null]);
        $this->self->update(['father_id' => $father->id]);

        $answer = QuestAnswer::create([
            'user_id' => $this->user->id,
            'question_type' => 'birth_date',
            'question_key' => "birth_date:{$father->id}",
            'subject_type' => Person::class,
            'subject_id' => $father->id,
            'answer_kind' => 'skipped',
        ]);

        $this->assertNotContains("birth_date:{$father->id}", $this->candidateKeys($this->user));

        DB::table('quest_answers')->where('id', $answer->id)
            ->update(['created_at' => now()->subDays(8)]);

        $this->assertContains("birth_date:{$father->id}", $this->candidateKeys($this->user));
    }

    public function test_pas_de_question_encore_en_vie_pour_une_personne_nee_il_y_a_plus_de_120_ans(): void
    {
        $ancient = $this->completePerson(['user_id' => $this->user->id, 'birth_date' => now()->subYears(140)]);
        $elder = $this->completePerson(['user_id' => $this->user->id, 'birth_date' => now()->subYears(85)]);
        $this->self->update(['father_id' => $elder->id, 'mother_id' => $ancient->id]);

        $keys = $this->candidateKeys($this->user);

        $this->assertContains("death_date_old:{$ancient->id}", $keys);
        $this->assertNotContains("death_status:{$ancient->id}", $keys);
        $this->assertContains("death_status:{$elder->id}", $keys);
        $this->assertNotContains("death_date_old:{$elder->id}", $keys);
    }

    public function test_pas_de_question_metier_ni_mariage_pour_un_enfant(): void
    {
        $child = $this->completePerson([
            'user_id' => $this->user->id,
            'birth_date' => now()->subYears(10),
            'father_id' => $this->self->id,
        ]);

        $keys = $this->candidateKeys($this->user);

        $this->assertNotContains("job:{$child->id}", $keys);
        $this->assertNotContains("education:{$child->id}", $keys);
        $this->assertNotContains("residence:{$child->id}", $keys);
        $this->assertNotContains("marital_status:{$child->id}", $keys);
    }

    public function test_question_media_uniquement_pour_le_proprietaire_du_media(): void
    {
        $mine = Media::factory()->photo()->create(['user_id' => $this->user->id, 'taken_at' => null]);
        $theirs = Media::factory()->photo()->create(['taken_at' => null]);

        $keys = $this->candidateKeys($this->user);

        $this->assertContains("media_date:{$mine->id}", $keys);
        $this->assertNotContains("media_date:{$theirs->id}", $keys);
    }

    public function test_question_visage_uniquement_sur_media_accessible(): void
    {
        $mine = Media::factory()->photo()->create(['user_id' => $this->user->id]);
        $myFace = $mine->detectedFaces()->create([
            'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
            'status' => 'unmatched',
            'provider' => 'faceapi',
        ]);

        $theirs = Media::factory()->photo()->create();
        $theirFace = $theirs->detectedFaces()->create([
            'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
            'status' => 'unmatched',
            'provider' => 'faceapi',
        ]);

        $keys = $this->candidateKeys($this->user);

        $this->assertContains("face_identify:{$myFace->id}", $keys);
        $this->assertNotContains("face_identify:{$theirFace->id}", $keys);
    }
}
