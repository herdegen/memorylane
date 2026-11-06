<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Partager une branche avec une autre famille : Carla est des familles A et
 * B ; la branche de Robert (famille A) devient commune à B — sans les
 * vivants sauf accord, sans photos sauf les siennes si elle le demande.
 *
 * Arbre de A : Firmin (1870-1940) → Robert (1900-1980) → Claire (1925-2000)
 * et Camille (1960, vivante) → Gaspard (1990, vivant).
 */
class BranchShareTest extends TestCase
{
    use RefreshDatabase;

    private User $carla;

    private User $bruno;

    private Household $A;

    private Household $B;

    private array $p = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->carla = User::factory()->create();
        $this->bruno = User::factory()->create();
        $this->A = Household::factory()->create(['name' => 'Famille A', 'created_by' => $this->carla->id]);
        $this->B = Household::factory()->create(['name' => 'Famille B', 'created_by' => $this->bruno->id]);
        $this->A->members()->attach($this->carla->id);
        $this->B->members()->attach([$this->carla->id, $this->bruno->id]);
        $this->carla->forceFill(['current_household_id' => $this->A->id])->save();

        $make = function (string $key, string $birth, ?string $death, ?string $father = null, ?string $mother = null) {
            $this->p[$key] = Person::factory()->create([
                'user_id' => $this->carla->id, 'name' => ucfirst($key), 'birth_date' => $birth, 'death_date' => $death,
                'father_id' => $father ? $this->p[$father]->id : null, 'mother_id' => $mother ? $this->p[$mother]->id : null,
            ]);
            $this->A->people()->syncWithoutDetaching([$this->p[$key]->id]);
            $this->B->people()->detach($this->p[$key]->id);
        };
        $make('firmin', '1870-01-01', '1940-01-01');
        $make('robert', '1900-01-01', '1980-01-01', 'firmin');
        $make('claire', '1925-01-01', '2000-01-01', 'robert');
        $make('camille', '1960-01-01', null, 'robert');
        $make('gaspard', '1990-01-01', null, null, 'camille');
    }

    private function visibleToBruno(): array
    {
        return collect($this->p)->filter(fn ($person) => Person::visibleTo($this->bruno->fresh())->whereKey($person->id)->exists())->keys()->sort()->values()->all();
    }

    public function test_apercu_puis_partage_des_descendants_sans_les_vivants(): void
    {
        $q = ['household_id' => $this->B->id, 'scope' => 'descendants'];
        $this->actingAs($this->carla)->getJson("/people/{$this->p['robert']->id}/share-branch?".http_build_query($q))
            ->assertOk()->assertJson(['family' => 'Famille B', 'branch' => 4, 'living' => 2, 'living_included' => false, 'to_share' => 2, 'already' => 0]);

        $this->actingAs($this->carla)->postJson("/people/{$this->p['robert']->id}/share-branch", $q)->assertOk()->assertJson(['shared' => 2]);

        $this->assertSame(['claire', 'robert'], $this->visibleToBruno());
    }

    public function test_avec_les_vivants_et_vers_les_ancetres(): void
    {
        $this->actingAs($this->carla)->postJson("/people/{$this->p['robert']->id}/share-branch", ['household_id' => $this->B->id, 'scope' => 'descendants', 'living' => true])
            ->assertOk()->assertJson(['shared' => 4]);
        $this->actingAs($this->carla)->postJson("/people/{$this->p['robert']->id}/share-branch", ['household_id' => $this->B->id, 'scope' => 'ancestors'])
            ->assertOk()->assertJson(['shared' => 1]); // Firmin ; Robert y est déjà

        $this->assertSame(['camille', 'claire', 'firmin', 'gaspard', 'robert'], $this->visibleToBruno());
    }

    public function test_ses_photos_seulement_sur_demande(): void
    {
        $photo = Media::factory()->create(['user_id' => $this->carla->id, 'type' => 'photo']);
        $photo->people()->attach($this->p['robert']->id);
        $q = ['household_id' => $this->B->id, 'scope' => 'person'];

        $this->actingAs($this->carla)->postJson("/people/{$this->p['robert']->id}/share-branch", $q)->assertOk()->assertJson(['photos' => 0]);
        $this->assertFalse(Media::accessibleBy($this->bruno->fresh())->whereKey($photo->id)->exists());

        $this->actingAs($this->carla)->postJson("/people/{$this->p['robert']->id}/share-branch", $q + ['photos' => true])->assertOk()->assertJson(['photos' => 1]);
        $this->assertTrue(Media::accessibleBy($this->bruno->fresh())->whereKey($photo->id)->exists());
    }

    public function test_inviter_depuis_une_fiche_commune_mene_a_la_famille_courante(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $this->p['camille']->update(['birth_date' => '1990-01-01']);
        $this->actingAs($this->carla)->postJson("/people/{$this->p['camille']->id}/share-branch", ['household_id' => $this->B->id, 'scope' => 'person', 'living' => true])->assertOk();

        // Elle navigue dans la famille B : l'invitée entre dans B, pas dans A.
        $this->carla->forceFill(['current_household_id' => $this->B->id])->save();
        $invitation = app(\App\Services\InvitationService::class)->invite($this->carla->fresh(), 'camille@example.fr', $this->p['camille']->fresh());

        $this->assertSame($this->B->id, $invitation->household_id);
    }

    public function test_pour_bien_demarrer_cherche_les_personnes_de_ses_autres_familles(): void
    {
        $this->B->people()->attach($this->p['robert']->id);
        $names = fn (User $u, string $q) => collect($this->actingAs($u)->getJson("/households/{$this->B->id}/branch-candidates?q={$q}")->assertOk()->json())->pluck('name')->all();

        // Carla voit Claire et Camille (famille A), pas Robert (déjà dans B).
        $this->assertSame(['Claire'], $names($this->carla, 'Cla'));
        $this->assertSame(['Camille'], $names($this->carla, 'Cam'));
        $this->assertSame([], $names($this->carla, 'Rob'));
        // Bruno ne voit pas la famille A.
        $this->assertSame([], $names($this->bruno, 'Cla'));
        // Hors de la famille : refusé.
        $this->actingAs(User::factory()->create())->getJson("/households/{$this->B->id}/branch-candidates?q=Cla")->assertForbidden();
    }

    public function test_refus(): void
    {
        // Vers une famille dont on n'est pas membre, ou un cercle d'amis : non.
        $other = Household::factory()->create(['created_by' => $this->bruno->id]);
        $friends = Household::factory()->friends()->create(['created_by' => $this->carla->id]);
        $friends->members()->attach($this->carla->id);
        foreach ([$other, $friends] as $target) {
            $this->actingAs($this->carla)->postJson("/people/{$this->p['robert']->id}/share-branch", ['household_id' => $target->id, 'scope' => 'person'])->assertForbidden();
        }
        // Une fiche qu'on ne voit pas : introuvable.
        $this->actingAs($this->bruno)->postJson("/people/{$this->p['robert']->id}/share-branch", ['household_id' => $this->B->id, 'scope' => 'person'])->assertNotFound();
        $this->assertSame([], $this->visibleToBruno());
    }
}
