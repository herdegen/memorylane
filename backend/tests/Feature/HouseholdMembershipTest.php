<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cercles : création et gestion des membres réservées à l'administrateur,
 * cercle courant (sélecteur de l'en-tête).
 */
class HouseholdMembershipTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
    }

    private function household(array $members = [], string $kind = 'family'): Household
    {
        $h = Household::factory()->create(['created_by' => $this->admin->id, 'kind' => $kind]);
        foreach ($members as $m) {
            $h->members()->attach($m->id);
        }

        return $h;
    }

    public function test_l_admin_cree_un_cercle_et_en_fait_partie(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/households', ['name' => 'Les copains', 'kind' => 'friends'])
            ->assertCreated();

        $household = Household::first();
        $this->assertSame('Les copains', $household->name);
        $this->assertSame('friends', $household->kind);
        $this->assertTrue($household->isMember($this->admin));
    }

    public function test_l_admin_peut_creer_un_cercle_sans_en_faire_partie(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/households', ['name' => 'Famille X', 'join' => false])
            ->assertCreated();

        $household = Household::first();
        $this->assertSame(config('memorylane.default_mode'), $household->kind);
        $this->assertFalse($household->isMember($this->admin));
    }

    public function test_un_membre_cree_son_cercle_d_amis_mais_pas_un_cercle_famille(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/households', ['name' => 'Famille à moi', 'kind' => 'family'])->assertForbidden();

        $this->actingAs($user)->postJson('/households', ['name' => 'Mes potes', 'kind' => 'friends', 'join' => false])->assertCreated();
        $circle = Household::firstWhere('name', 'Mes potes');
        $this->assertSame('friends', $circle->kind);
        $this->assertTrue($circle->isMember($user)); // toujours membre de son cercle
        $this->assertTrue($circle->isManagedBy($user));
    }

    public function test_le_responsable_gere_son_cercle_d_amis_pas_ceux_des_autres(): void
    {
        [$owner, $friend, $other] = User::factory()->count(3)->create()->all();
        $this->actingAs($owner)->postJson('/households', ['name' => 'Les potes', 'kind' => 'friends'])->assertCreated();
        $circle = Household::firstWhere('name', 'Les potes');

        $this->actingAs($owner)->postJson("/households/{$circle->id}/members", ['user_id' => $friend->id])->assertCreated();
        $this->actingAs($friend)->postJson("/households/{$circle->id}/members", ['user_id' => $other->id])->assertForbidden(); // réservé au responsable
        $this->actingAs($owner)->patchJson("/households/{$circle->id}", ['invite_policy' => 'everyone'])->assertOk();
        $this->actingAs($friend)->postJson("/households/{$circle->id}/members", ['user_id' => $other->id])->assertCreated();

        $this->actingAs($friend)->deleteJson("/households/{$circle->id}/members/{$other->id}")->assertForbidden();
        $this->actingAs($owner)->deleteJson("/households/{$circle->id}/members/{$other->id}")->assertOk();

        // Il ne gère pas un cercle famille, même s'il en est membre.
        $family = $this->household([$owner]);
        $this->actingAs($owner)->patchJson("/households/{$family->id}", ['invite_policy' => 'admin'])->assertForbidden();

        $this->actingAs($owner)->deleteJson("/households/{$circle->id}")->assertOk();
    }

    public function test_index_ne_liste_que_mes_cercles_sauf_pour_l_admin(): void
    {
        $user = User::factory()->create();
        $mine = $this->household([$user]);
        $this->household(); // cercle sans lui

        $this->actingAs($user)->getJson('/households')->assertOk()
            ->assertJsonCount(1)->assertJsonPath('0.id', $mine->id);

        $this->actingAs($this->admin)->getJson('/households')->assertOk()->assertJsonCount(2);
    }

    public function test_un_non_membre_ne_voit_pas_le_cercle(): void
    {
        $member = User::factory()->create();
        $stranger = User::factory()->create();
        $household = $this->household([$member]);

        $this->actingAs($stranger)->get("/households/{$household->id}")->assertForbidden();
        $this->actingAs($member)->get("/households/{$household->id}")->assertOk();
        $this->actingAs($this->admin)->get("/households/{$household->id}")->assertOk();
    }

    public function test_cercle_ouvert_tout_membre_ajoute_mais_seul_l_admin_retire(): void
    {
        $member = User::factory()->create();
        $target = User::factory()->create();
        $household = $this->household([$member]); // famille : ouvert par défaut

        $this->actingAs($member)
            ->postJson("/households/{$household->id}/members", ['user_id' => $target->id])
            ->assertCreated();

        $this->actingAs($member)
            ->deleteJson("/households/{$household->id}/members/{$target->id}")
            ->assertForbidden();
    }

    public function test_seul_l_admin_ajoute_et_retire_des_membres(): void
    {
        $member = User::factory()->create();
        $target = User::factory()->create();
        $household = $this->household([$member]);
        $household->update(['invite_policy' => 'admin']);

        $this->actingAs($member)
            ->postJson("/households/{$household->id}/members", ['user_id' => $target->id])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->postJson("/households/{$household->id}/members", ['user_id' => $target->id])
            ->assertCreated();
        $this->assertTrue($household->fresh()->isMember($target));

        $this->actingAs($member)
            ->deleteJson("/households/{$household->id}/members/{$target->id}")
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->deleteJson("/households/{$household->id}/members/{$target->id}")
            ->assertOk();
        $this->assertFalse($household->fresh()->isMember($target));
    }

    public function test_ajouter_a_un_cercle_d_amis_y_rattache_sa_fiche(): void
    {
        $friend = User::factory()->create();
        $self = Person::factory()->create(['user_id' => $friend->id]);
        $friend->forceFill(['person_id' => $self->id])->save();
        $household = $this->household([], 'friends');

        $this->actingAs($this->admin)
            ->postJson("/households/{$household->id}/members", ['user_id' => $friend->id])
            ->assertCreated();

        $this->assertTrue($household->people()->whereKey($self->id)->exists());
    }

    public function test_reglage_qui_peut_inviter_par_l_admin(): void
    {
        $member = User::factory()->create();
        $household = $this->household([$member]);

        $this->actingAs($member)->patchJson("/households/{$household->id}", ['invite_policy' => 'admin'])->assertForbidden();
        $this->actingAs($this->admin)->patchJson("/households/{$household->id}", ['invite_policy' => 'admin'])->assertOk();
        $this->assertSame('admin', $household->fresh()->invite_policy);

        $this->actingAs($this->admin)->patchJson("/households/{$household->id}", ['invite_policy' => 'n importe'])->assertUnprocessable();
    }

    public function test_reglage_par_defaut_selon_le_type(): void
    {
        $this->actingAs($this->admin)->postJson('/households', ['name' => 'Fam', 'kind' => 'family'])->assertCreated();
        $this->actingAs($this->admin)->postJson('/households', ['name' => 'Potes', 'kind' => 'friends'])->assertCreated();

        $this->assertSame('everyone', Household::where('name', 'Fam')->value('invite_policy'));
        $this->assertSame('admin', Household::where('name', 'Potes')->value('invite_policy'));
    }

    public function test_un_membre_quitte_le_cercle(): void
    {
        $member = User::factory()->create();
        $household = $this->household([$member]);

        $this->actingAs($member)->postJson("/households/{$household->id}/leave")->assertOk();
        $this->assertFalse($household->fresh()->isMember($member));
    }

    public function test_seul_l_admin_supprime_le_cercle(): void
    {
        $member = User::factory()->create();
        $household = $this->household([$member]);

        $this->actingAs($member)->deleteJson("/households/{$household->id}")->assertForbidden();

        $this->actingAs($this->admin)->deleteJson("/households/{$household->id}")->assertOk();
        $this->assertDatabaseMissing('households', ['id' => $household->id]);
    }

    public function test_choisir_son_cercle_courant(): void
    {
        $user = User::factory()->create();
        $family = $this->household([$user]);
        $friends = $this->household([$user], 'friends');
        $other = $this->household([], 'friends');

        $this->assertSame('family', $user->mode());

        $this->actingAs($user)->post('/circles/current', ['household_id' => $friends->id])->assertRedirect();
        $this->assertSame($friends->id, $user->fresh()->current_household_id);
        $this->assertSame('friends', $user->fresh()->mode());

        // Pas un cercle dont on n'est pas membre.
        $this->actingAs($user)->post('/circles/current', ['household_id' => $other->id])->assertForbidden();
        $this->assertSame($friends->id, $user->fresh()->current_household_id);
    }

    public function test_props_partagees_du_cercle(): void
    {
        $user = User::factory()->create();
        $this->household([$user], 'friends');

        $this->actingAs($user)->get('/dashboard')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('circle.mode', 'friends')
                ->where('circle.family', false)
                ->where('circle.can_manage', false)
                ->has('circle.list', 1)
                ->etc());
    }

    public function test_compte_amis_seulement_redirige_de_l_arbre_vers_le_reseau(): void
    {
        $user = User::factory()->create();
        $this->household([$user], 'friends');

        $this->actingAs($user)->get('/family-tree')->assertRedirect('/network');
    }
}
