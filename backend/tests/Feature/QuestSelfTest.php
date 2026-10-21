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
 * Questions de base posées au membre sur lui-même (date de naissance, ville),
 * dans un cercle d'amis comme dans la famille, en tête du lot.
 */
class QuestSelfTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Person $me;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake([
            'api-adresse.data.gouv.fr/*' => Http::response(['features' => [[
                'geometry' => ['coordinates' => [2.4359, 48.5600]],
                'properties' => ['city' => 'Mennecy'],
            ]]]),
        ]);

        $this->user = User::factory()->create();
        $this->me = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null, 'address' => null]);
        $this->user->forceFill(['person_id' => $this->me->id])->save();
    }

    private function joinCircle(string $kind): Household
    {
        $circle = Household::create(['name' => 'Cercle', 'kind' => $kind, 'invite_policy' => Household::INVITE_ADMIN, 'created_by' => $this->user->id]);
        $circle->addMember($this->user, $this->user);
        $this->user->forceFill(['current_household_id' => $circle->id])->save();

        return $circle;
    }

    private function batch(): array
    {
        $service = app(QuestService::class);
        $service->forget($this->user);

        return array_map(fn ($c) => $c['type'].':'.$c['subject_id'], $service->candidates($this->user->fresh()));
    }

    public function test_dans_un_cercle_d_amis_on_demande_sa_date_de_naissance_et_sa_ville_en_premier(): void
    {
        $this->joinCircle(Household::KIND_FRIENDS);
        $friend = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null, 'in_family_directory' => false]);
        Household::first()->people()->attach($friend->id);

        $batch = $this->batch();

        $this->assertSame(
            ["birth_date:{$this->me->id}", "address:{$this->me->id}"],
            array_slice($batch, 0, 2),
        );
        $this->assertContains("birth_date:{$friend->id}", $batch);
    }

    public function test_dans_la_famille_aussi_sans_doublon(): void
    {
        $this->joinCircle(Household::KIND_FAMILY);

        $batch = $this->batch();

        $this->assertSame("birth_date:{$this->me->id}", $batch[0]);
        $this->assertSame("address:{$this->me->id}", $batch[1]);
        $this->assertSame(1, count(array_keys($batch, "address:{$this->me->id}")));
        $this->assertSame(1, count(array_keys($batch, "birth_date:{$this->me->id}")));
    }

    public function test_question_au_tutoiement_et_la_ville_suffit(): void
    {
        $this->joinCircle(Household::KIND_FRIENDS);
        $this->me->update(['birth_date' => '1985-03-02']);
        app(QuestService::class)->forget($this->user);

        $this->actingAs($this->user)->getJson('/quests/next')
            ->assertOk()
            ->assertJsonPath('question.type', 'address')
            ->assertJsonPath('question.self', true)
            ->assertJsonPath('question.prompt', 'Dans quelle ville habites-tu ? (la ville suffit)');

        $this->actingAs($this->user)->postJson('/quests/answer', [
            'question_type' => 'address',
            'subject_id' => $this->me->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => 'Mennecy'],
        ])->assertSuccessful();

        $this->assertSame('Mennecy', $this->me->fresh()->address_city);
        $this->assertNotContains("address:{$this->me->id}", $this->batch());
    }

    public function test_rien_quand_tout_est_rempli(): void
    {
        $this->joinCircle(Household::KIND_FRIENDS);
        $this->me->update(['birth_date' => '1985-03-02', 'address' => 'Mennecy']);

        $this->assertNotContains("birth_date:{$this->me->id}", $this->batch());
        $this->assertNotContains("address:{$this->me->id}", $this->batch());
    }
}
