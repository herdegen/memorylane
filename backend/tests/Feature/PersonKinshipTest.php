<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use App\Services\GenealogyService;
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
            ->assertJsonPath('apex_index', 1)
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

    public function test_cousine_germaine_et_ancetre_commun(): void
    {
        $grandpa = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $father = Person::factory()->male()->create(['user_id' => $this->user->id, 'father_id' => $grandpa->id]);
        $this->self->update(['father_id' => $father->id]);
        $uncle = Person::factory()->male()->create(['user_id' => $this->user->id, 'father_id' => $grandpa->id]);
        $cousin = Person::factory()->female()->create(['user_id' => $this->user->id, 'father_id' => $uncle->id]);

        $response = $this->actingAs($this->user)->getJson("/people/{$cousin->id}/kinship");

        $response->assertOk()
            ->assertJsonPath('found', true)
            ->assertJsonPath('relation_label', 'votre cousine germaine')
            // L'ancêtre commun (grand-père) est le sommet du chemin.
            ->assertJsonPath('apex_index', 2)
            ->assertJsonPath('path.2.id', $grandpa->id);
    }

    public function test_libelles_espagnols(): void
    {
        app()->setLocale('es');

        $cases = [
            [['parent'], 'F', 'tu madre'],
            [['parent', 'parent'], 'F', 'tu abuela'],
            [['parent', 'parent', 'parent'], 'M', 'tu bisabuelo'],
            [['child', 'child'], 'F', 'tu nieta'],
            [['parent', 'child', 'child'], 'M', 'tu sobrino'],
            [['parent', 'parent', 'child'], 'F', 'tu tía'],
            [['parent', 'parent', 'child', 'child'], 'F', 'tu prima hermana'],
            [['spouse', 'parent'], 'F', 'tu suegra'],
            [['parent', 'child', 'spouse'], 'M', 'tu cuñado'],
            [['child', 'spouse'], 'M', 'tu yerno'],
            [['child', 'spouse'], 'F', 'tu nuera'],
            [['parent', 'spouse'], 'F', 'tu madrastra'],
            [['spouse', 'child'], 'M', 'tu hijastro'],
            [['spouse', 'parent', 'parent'], 'F', 'tu abuela política'],
            [['parent', 'parent', 'child', 'spouse'], 'M', 'tu tío político'],
            [['parent', 'child'], null, 'tu hermano'],
        ];
        foreach ($cases as [$edges, $gender, $expected]) {
            $this->assertSame($expected, GenealogyService::relationLabel($edges, $gender), implode('.', $edges));
        }

        // Chemins exotiques : null, comme en français.
        $this->assertNull(GenealogyService::relationLabel(['parent', 'spouse', 'child', 'spouse'], 'M'));
        $this->assertNull(GenealogyService::relationLabel(['parent', 'parent', 'child', 'child', 'spouse', 'parent', 'child', 'child'], 'M'));
    }

    public function test_espagnol_null_aux_memes_endroits_que_le_francais(): void
    {
        $edges = ['parent', 'child', 'spouse'];
        $paths = [];
        // Tous les chemins de 1 à 4 pas.
        $grow = [[]];
        for ($i = 0; $i < 4; $i++) {
            $next = [];
            foreach ($grow as $p) {
                foreach ($edges as $e) {
                    $next[] = [...$p, $e];
                }
            }
            $paths = [...$paths, ...$next];
            $grow = $next;
        }

        foreach ($paths as $path) {
            foreach (['F', 'M', null] as $gender) {
                app()->setLocale('fr');
                $fr = GenealogyService::relationLabel($path, $gender);
                app()->setLocale('es');
                $es = GenealogyService::relationLabel($path, $gender);
                $this->assertSame($fr === null, $es === null, implode('.', $path));
                if ($es !== null) {
                    $this->assertStringStartsWith('tu ', $es);
                }
            }
        }
    }
}
