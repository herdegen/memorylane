<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Responsables de cercle : le créateur d'une famille (ou d'un groupe d'amis)
 * la gère, nomme d'autres responsables, et le cercle en garde toujours un ;
 * le dernier responsable qui part désigne son successeur.
 */
class HouseholdManagersTest extends TestCase
{
    use RefreshDatabase;

    public function test_chacun_cree_sa_famille_et_en_devient_responsable(): void
    {
        $founder = User::factory()->create();

        $this->actingAs($founder)->postJson('/households', ['name' => 'Famille Penninger', 'kind' => 'family'])->assertCreated();

        $family = Household::firstWhere('name', 'Famille Penninger');
        $this->assertTrue($family->isFamily());
        $this->assertTrue($family->isManager($founder));
        $this->assertTrue($family->isManagedBy($founder));
    }

    public function test_nommer_un_responsable_et_garder_toujours_un(): void
    {
        [$founder, $cousin] = User::factory()->count(2)->create()->all();
        $family = Household::factory()->create(['created_by' => $founder->id]);
        $family->addMember($founder, $founder, Household::ROLE_MANAGER);
        $family->addMember($cousin, $founder);

        // Un simple membre ne gère pas.
        $this->actingAs($cousin)->patchJson("/households/{$family->id}/members/{$founder->id}/role", ['role' => 'member'])->assertForbidden();

        // Le responsable nomme la cousine ; elle peut alors gérer.
        $this->actingAs($founder)->patchJson("/households/{$family->id}/members/{$cousin->id}/role", ['role' => 'manager'])->assertOk();
        $this->assertTrue($family->isManagedBy($cousin));

        // Elle lui retire le rôle ; puis on ne peut plus retirer le dernier.
        $this->actingAs($cousin)->patchJson("/households/{$family->id}/members/{$founder->id}/role", ['role' => 'member'])->assertOk();
        $this->actingAs($cousin)->patchJson("/households/{$family->id}/members/{$cousin->id}/role", ['role' => 'member'])
            ->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertTrue($family->isManager($cousin));
    }

    public function test_ré_inviter_un_responsable_ne_le_retrograde_pas(): void
    {
        $founder = User::factory()->create();
        $family = Household::factory()->create(['created_by' => $founder->id]);
        $family->addMember($founder, $founder, Household::ROLE_MANAGER);

        $family->addMember($founder, $founder);

        $this->assertTrue($family->isManager($founder));
    }

    public function test_le_dernier_responsable_qui_part_designe_son_successeur(): void
    {
        [$founder, $cousin] = User::factory()->count(2)->create()->all();
        $family = Household::factory()->create(['created_by' => $founder->id]);
        $family->addMember($founder, $founder, Household::ROLE_MANAGER);
        $family->addMember($cousin, $founder);

        $this->actingAs($founder)->getJson("/households/{$family->id}/leave")
            ->assertOk()->assertJsonPath('needs_successor', true);
        $this->actingAs($founder)->postJson("/households/{$family->id}/leave", ['photos' => 'keep'])
            ->assertUnprocessable()->assertJsonValidationErrors('successor_id');

        $this->actingAs($founder)->postJson("/households/{$family->id}/leave", ['photos' => 'keep', 'successor_id' => $cousin->id])->assertSuccessful();

        $this->assertFalse($family->isMember($founder));
        $this->assertTrue($family->isManager($cousin));
    }
}
