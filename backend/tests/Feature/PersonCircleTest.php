<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ajouter une fiche qui existe déjà à un cercle (Marion, de la famille, dans
 * la bande de Matthieu ; un ami dans la famille), ou l'en retirer.
 */
class PersonCircleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $matthieu;   // famille + bande
    private User $aurele;     // bande seulement
    private User $cousin;     // famille seulement
    private Household $family;
    private Household $bande;
    private Person $marion;   // fiche familiale
    private Person $damien;   // fiche de la bande

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
        [$this->matthieu, $this->aurele, $this->cousin] = User::factory()->count(3)->create()->all();

        $this->family = Household::factory()->create(['created_by' => $this->admin->id]);
        $this->bande = Household::factory()->friends()->create(['created_by' => $this->admin->id, 'invite_policy' => 'everyone']);
        $this->family->members()->attach([$this->matthieu->id, $this->cousin->id]);
        $this->bande->members()->attach([$this->matthieu->id, $this->aurele->id]);

        $this->marion = Person::factory()->create(['user_id' => $this->admin->id, 'name' => 'Marion Herdegen']);
        $this->damien = Person::factory()->create(['user_id' => $this->admin->id, 'name' => 'Damien Dussaux', 'in_family_directory' => false]);
        $this->damien->circles()->attach($this->bande->id);
    }

    public function test_un_pont_ajoute_une_fiche_familiale_a_son_cercle_d_amis(): void
    {
        // Aurèle ne voit pas Marion.
        $this->actingAs($this->aurele)->getJson("/people/{$this->marion->id}/timeline")->assertNotFound();

        $this->actingAs($this->matthieu)->getJson("/people/{$this->marion->id}/circles")->assertOk()
            ->assertJsonPath('in.0.kind', 'family')
            ->assertJsonPath('can_add.0.id', $this->bande->id);

        $this->actingAs($this->matthieu)->postJson("/people/{$this->marion->id}/circles", ['household_id' => $this->bande->id])->assertOk();

        // Désormais oui — et elle reste dans la famille.
        $this->actingAs($this->aurele)->getJson("/people/{$this->marion->id}/timeline")->assertOk();
        $this->assertTrue($this->marion->fresh()->in_family_directory);
    }

    public function test_on_ne_peut_pas_ajouter_une_fiche_qu_on_ne_voit_pas_ni_a_un_cercle_dont_on_n_est_pas(): void
    {
        $this->actingAs($this->aurele)->postJson("/people/{$this->marion->id}/circles", ['household_id' => $this->bande->id])->assertNotFound();
        $this->actingAs($this->cousin)->postJson("/people/{$this->marion->id}/circles", ['household_id' => $this->bande->id])->assertForbidden();
    }

    public function test_le_reglage_qui_peut_inviter_s_applique(): void
    {
        $this->bande->update(['invite_policy' => 'admin']);

        $this->actingAs($this->matthieu)->postJson("/people/{$this->marion->id}/circles", ['household_id' => $this->bande->id])->assertForbidden();
        $this->actingAs($this->admin)->postJson("/people/{$this->marion->id}/circles", ['household_id' => $this->bande->id])->assertOk();
    }

    public function test_ajouter_un_ami_a_la_famille(): void
    {
        $this->actingAs($this->cousin)->getJson("/people/{$this->damien->id}/timeline")->assertNotFound();

        $this->actingAs($this->matthieu)->postJson("/people/{$this->damien->id}/circles", ['household_id' => $this->family->id])->assertOk();

        $this->assertTrue($this->damien->fresh()->in_family_directory);
        $this->actingAs($this->cousin)->getJson("/people/{$this->damien->id}/timeline")->assertOk();
    }

    public function test_retrait_par_l_auteur_ou_l_admin_et_famille_par_l_admin(): void
    {
        $this->actingAs($this->matthieu)->postJson("/people/{$this->marion->id}/circles", ['household_id' => $this->bande->id])->assertOk();

        $this->actingAs($this->aurele)->deleteJson("/people/{$this->marion->id}/circles/{$this->bande->id}")->assertForbidden();
        $this->actingAs($this->matthieu)->deleteJson("/people/{$this->marion->id}/circles/{$this->bande->id}")->assertOk();
        $this->actingAs($this->aurele)->getJson("/people/{$this->marion->id}/timeline")->assertNotFound();

        $this->actingAs($this->matthieu)->deleteJson("/people/{$this->marion->id}/circles/{$this->family->id}")->assertForbidden();
    }

    public function test_les_selecteurs_proposent_les_fiches_d_ailleurs_sans_doublon(): void
    {
        $this->matthieu->forceFill(['current_household_id' => $this->bande->id])->save();

        $list = collect($this->actingAs($this->matthieu->fresh())->getJson('/people?with_elsewhere=1')->assertOk()->json())->keyBy('id');

        $this->assertArrayNotHasKey('elsewhere', $list[$this->damien->id]);
        $this->assertTrue($list[$this->marion->id]['elsewhere']);
        $this->assertSame('Famille', $list[$this->marion->id]['elsewhere_label']);

        // Sans le paramètre (page Amis), seulement le cercle.
        $plain = collect($this->actingAs($this->matthieu->fresh())->getJson('/people')->json())->pluck('id');
        $this->assertFalse($plain->contains($this->marion->id));

        // Aurèle (bande seulement) ne se voit rien proposer de la famille.
        $aurele = collect($this->actingAs($this->aurele)->getJson('/people?with_elsewhere=1')->json())->pluck('id');
        $this->assertFalse($aurele->contains($this->marion->id));
    }
}
