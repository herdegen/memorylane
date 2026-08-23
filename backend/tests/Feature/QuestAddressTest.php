<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Services\QuestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Quête « adresse » (gamification) : demandée uniquement pour les fiches
 * liées aux membres de son foyer (soi inclus) quand l'adresse manque, et
 * applicable uniquement par le foyer/propriétaire/admin (donnée sensible).
 */
class QuestAddressTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Person $self;
    protected User $member;
    protected Person $memberPerson;
    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api-adresse.data.gouv.fr/*' => Http::response([
                'features' => [[
                    'geometry' => ['coordinates' => [2.441441, 48.860642]],
                    'properties' => ['city' => 'Montreuil'],
                ]],
            ]),
        ]);

        $this->user = User::factory()->create();
        $this->self = Person::factory()->create(['user_id' => $this->user->id]);
        $this->user->forceFill(['person_id' => $this->self->id])->save();

        $this->member = User::factory()->create();
        $this->memberPerson = Person::factory()->create(['user_id' => $this->member->id]);
        $this->member->forceFill(['person_id' => $this->memberPerson->id])->save();

        $this->household = Household::factory()->create(['created_by' => $this->user->id]);
        $this->household->members()->attach([$this->user->id, $this->member->id]);
    }

    /** Clés "type:subject_id" du lot de candidats (cache purgé avant). */
    private function candidateKeys(User $user): array
    {
        $service = app(QuestService::class);
        $service->forget($user);

        return array_map(fn ($c) => $c['type'].':'.$c['subject_id'], $service->candidates($user));
    }

    public function test_genere_la_question_adresse_pour_le_foyer_et_soi_meme(): void
    {
        $keys = $this->candidateKeys($this->user);

        $this->assertContains("address:{$this->memberPerson->id}", $keys);
        $this->assertContains("address:{$this->self->id}", $keys);
    }

    public function test_pas_de_question_adresse_hors_foyer(): void
    {
        $outsider = User::factory()->create();
        $outsiderPerson = Person::factory()->create(['user_id' => $outsider->id]);
        $outsider->forceFill(['person_id' => $outsiderPerson->id])->save();

        $this->assertNotContains(
            "address:{$outsiderPerson->id}",
            $this->candidateKeys($this->user),
        );
    }

    public function test_pas_de_question_adresse_quand_deja_renseignee(): void
    {
        $this->memberPerson->update(['address' => '1 place de la Mairie, 93100 Montreuil']);

        $this->assertNotContains(
            "address:{$this->memberPerson->id}",
            $this->candidateKeys($this->user),
        );
    }

    public function test_un_membre_du_foyer_peut_renseigner_l_adresse(): void
    {
        $response = $this->actingAs($this->user)->postJson('/quests/answer', [
            'question_type' => 'address',
            'subject_id' => $this->memberPerson->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '12 rue des Lilas, 93100 Montreuil'],
        ]);

        $response->assertCreated();

        $person = $this->memberPerson->fresh();
        $this->assertSame('12 rue des Lilas, 93100 Montreuil', $person->address);
        $this->assertSame('Montreuil', $person->address_city);
        $this->assertNotNull($person->address_latitude);
        $this->assertDatabaseHas('quest_answers', [
            'user_id' => $this->user->id,
            'question_key' => "address:{$this->memberPerson->id}",
            'answer_kind' => 'answered',
        ]);
    }

    public function test_un_utilisateur_hors_foyer_ne_peut_pas_renseigner_l_adresse(): void
    {
        $outsider = User::factory()->create();

        $response = $this->actingAs($outsider)->postJson('/quests/answer', [
            'question_type' => 'address',
            'subject_id' => $this->memberPerson->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '12 rue des Lilas, 93100 Montreuil'],
        ]);

        $response->assertForbidden();
        $this->assertNull($this->memberPerson->fresh()->address);
    }

    public function test_adresse_deja_remplie_renvoie_409_sans_ecraser(): void
    {
        $this->memberPerson->update(['address' => '1 place de la Mairie, 93100 Montreuil']);

        $response = $this->actingAs($this->user)->postJson('/quests/answer', [
            'question_type' => 'address',
            'subject_id' => $this->memberPerson->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '12 rue des Lilas, 93100 Montreuil'],
        ]);

        $response->assertStatus(409);
        $this->assertSame('1 place de la Mairie, 93100 Montreuil', $this->memberPerson->fresh()->address);
    }
}
