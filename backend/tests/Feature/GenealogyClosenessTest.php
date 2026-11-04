<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Services\GenealogyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proximité ressentie (liste des personnes) : la branche latérale (tante,
 * neveux, cousins) passe devant les arrière-grands-parents.
 */
class GenealogyClosenessTest extends TestCase
{
    use RefreshDatabase;

    private function person(string $name, ?Person $father = null, ?Person $mother = null): Person
    {
        return Person::factory()->create([
            'name' => $name,
            'father_id' => $father?->id,
            'mother_id' => $mother?->id,
        ]);
    }

    private function marry(Person $a, Person $b): void
    {
        DB::table('person_relationships')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'person1_id' => $a->id,
            'person2_id' => $b->id,
            'type' => 'spouse',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_la_famille_laterale_passe_devant_les_arriere_grands_parents(): void
    {
        $ggp = $this->person('Arrière-grand-père');
        $gp = $this->person('Grand-père', $ggp);
        $greatAunt = $this->person('Grand-tante', $ggp);
        $father = $this->person('Père', $gp);
        $aunt = $this->person('Tante', $gp);
        $cousin = $this->person('Cousin', null, $aunt);
        $cousinChild = $this->person('Enfant du cousin', $cousin);
        $me = $this->person('Moi', $father);
        $sister = $this->person('Sœur', $father);
        $nephew = $this->person('Neveu', null, $sister);
        $child = $this->person('Fils', $me);
        $spouse = $this->person('Épouse');
        $this->marry($me, $spouse);
        $fatherInLaw = $this->person('Beau-père');
        $spouse->update(['father_id' => $fatherInLaw->id]);
        $sonInLaw = $this->person('Gendre');
        $this->marry($child, $sonInLaw);

        // Petits-enfants d'un cousin de mon grand-père : à peine aussi proches
        // qu'un arrière-grand-parent.
        $gggp = $this->person('Trisaïeul');
        $ggp->update(['father_id' => $gggp->id]);
        $ggpBrother = $this->person('Frère de l’arrière-grand-père', $gggp);
        $gpCousin = $this->person('Cousin du grand-père', $ggpBrother);
        $gpCousinChild = $this->person('Enfant du cousin du grand-père', $gpCousin);
        $gpCousinGrandchild = $this->person('Petit-enfant du cousin du grand-père', $gpCousinChild);

        $score = app(GenealogyService::class)->closeness($me->id);
        $order = collect($score)->sort()->keys()->all();
        $rank = fn (Person $p) => array_search($p->id, $order, true);

        $this->assertSame(0.0, $score[$me->id]);
        $this->assertLessThan($rank($child), $rank($spouse));
        $this->assertLessThan($rank($father), $rank($child));
        $this->assertLessThan($rank($sister), $rank($father));
        $this->assertLessThan($rank($ggp), $rank($aunt));
        $this->assertLessThan($rank($ggp), $rank($nephew));
        $this->assertLessThan($rank($ggp), $rank($cousin));
        $this->assertLessThan($rank($ggp), $rank($cousinChild));
        $this->assertLessThan($rank($ggp), $rank($greatAunt));
        $this->assertLessThan($rank($cousin), $rank($aunt));
        $this->assertLessThan($rank($cousin), $rank($fatherInLaw));
        $this->assertLessThan($rank($aunt), $rank($sonInLaw));
        $this->assertEqualsWithDelta($score[$ggp->id], $score[$gpCousinGrandchild->id], 0.5);
        $this->assertGreaterThan($score[$ggp->id], $score[$gggp->id]);
    }

    public function test_la_liste_des_personnes_trie_par_proximite_ressentie(): void
    {
        $ggp = $this->person('Arrière-grand-père');
        $gp = $this->person('Grand-père', $ggp);
        $father = $this->person('Père', $gp);
        $aunt = $this->person('Tante', $gp);
        $me = $this->person('Moi', $father);

        $user = \App\Models\User::factory()->create(['person_id' => $me->id]);
        $me->update(['user_id' => $user->id]);
        $family = \App\Models\Household::factory()->create(['created_by' => $user->id]);
        $family->members()->attach($user->id);

        $proximity = collect($this->actingAs($user)->getJson('/people')->assertOk()->json())->pluck('proximity', 'id');

        $this->assertSame(0.0, (float) $proximity[$me->id]);
        $this->assertLessThan($proximity[$ggp->id], $proximity[$aunt->id]);
    }
}
