<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Lien de parenté (GET /people/{person}/kinship) : plus court chemin entre la
 * fiche « moi » du visiteur et une personne, avec libellés français.
 */
class PersonKinshipTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Person $self;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->self = Person::factory()->male()->create(['user_id' => $this->user->id, 'birth_date' => null]);
        $this->user->forceFill(['person_id' => $this->self->id])->save();
    }

    private function marry(Person $a, Person $b): void
    {
        DB::table('person_relationships')->insert([
            'id' => (string) Str::uuid(),
            'person1_id' => $a->id,
            'person2_id' => $b->id,
            'type' => 'spouse',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_pere_direct(): void
    {
        $father = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $this->self->update(['father_id' => $father->id]);

        $response = $this->actingAs($this->user)->getJson("/people/{$father->id}/kinship");

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('steps', 1)
            ->assertJsonPath('relation_label', 'votre père')
            ->assertJsonPath('path.0.id', $this->self->id)
            ->assertJsonPath('path.1.id', $father->id)
            ->assertJsonPath('edge_labels.0', 'son père');
    }

    public function test_belle_soeur_conjointe_du_frere(): void
    {
        $father = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $this->self->update(['father_id' => $father->id]);
        $brother = Person::factory()->male()->create(['user_id' => $this->user->id, 'father_id' => $father->id]);
        $sisterInLaw = Person::factory()->female()->create(['user_id' => $this->user->id]);
        $this->marry($brother, $sisterInLaw);

        $response = $this->actingAs($this->user)->getJson("/people/{$sisterInLaw->id}/kinship");

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('steps', 3)
            ->assertJsonPath('relation_label', 'votre belle-sœur');
    }

    public function test_sans_lien_found_false(): void
    {
        $stranger = Person::factory()->create(['user_id' => $this->user->id, 'father_id' => null, 'mother_id' => null]);

        $this->actingAs($this->user)
            ->getJson("/people/{$stranger->id}/kinship")
            ->assertOk()
            ->assertJsonPath('found', false);
    }

    public function test_sans_fiche_moi_422(): void
    {
        $other = User::factory()->create(); // pas de person_id
        $person = Person::factory()->create(['user_id' => $other->id]);

        $this->actingAs($other)
            ->getJson("/people/{$person->id}/kinship")
            ->assertStatus(422);
    }

    public function test_chemin_exotique_sans_libelle(): void
    {
        // Neveu d'un cousin par alliance : pas de libellé direct → null,
        // mais le chemin est renvoyé (le front affiche « relié en N liens »).
        $father = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $grandpa = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $father->update(['father_id' => $grandpa->id]);
        $this->self->update(['father_id' => $father->id]);
        $uncle = Person::factory()->male()->create(['user_id' => $this->user->id, 'father_id' => $grandpa->id]);
        $cousin = Person::factory()->male()->create(['user_id' => $this->user->id, 'father_id' => $uncle->id]);
        $cousinWife = Person::factory()->female()->create(['user_id' => $this->user->id]);
        $this->marry($cousin, $cousinWife);
        $wifeSibling = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $wifeParent = Person::factory()->female()->create(['user_id' => $this->user->id]);
        $cousinWife->update(['mother_id' => $wifeParent->id]);
        $wifeSibling->update(['mother_id' => $wifeParent->id]);
        $nephew = Person::factory()->male()->create(['user_id' => $this->user->id, 'father_id' => $wifeSibling->id]);

        $response = $this->actingAs($this->user)->getJson("/people/{$nephew->id}/kinship");

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('relation_label', null);
        $this->assertGreaterThanOrEqual(6, $response->json('steps'));
        $this->assertCount($response->json('steps') + 1, $response->json('path'));
    }
}
