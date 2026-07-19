<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Foyers (phase 2a) : création et gestion de l'appartenance.
 */
class HouseholdMembershipTest extends TestCase
{
    use RefreshDatabase;

    /** Crée un foyer avec $creator comme créateur + premier membre. */
    private function household(User $creator, array $members = []): Household
    {
        $h = Household::factory()->create(['created_by' => $creator->id]);
        $h->members()->attach($creator->id);
        foreach ($members as $m) {
            $h->members()->attach($m->id);
        }

        return $h;
    }

    public function test_creer_un_foyer_ajoute_le_createur_comme_membre(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/households', ['name' => 'Famille Test'])
            ->assertCreated();

        $household = Household::first();
        $this->assertSame('Famille Test', $household->name);
        $this->assertSame($user->id, $household->created_by);
        $this->assertTrue($household->isMember($user));
    }

    public function test_index_ne_liste_que_mes_foyers(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $mine = $this->household($user);
        $this->household($other); // foyer d'un autre

        $this->actingAs($user)
            ->getJson('/households')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id);
    }

    public function test_un_non_membre_ne_peut_pas_voir_le_foyer(): void
    {
        $creator = User::factory()->create();
        $stranger = User::factory()->create();
        $household = $this->household($creator);

        $this->actingAs($stranger)->get("/households/{$household->id}")->assertForbidden();
        $this->actingAs($creator)->get("/households/{$household->id}")->assertOk();
    }

    public function test_le_createur_invite_un_compte(): void
    {
        $creator = User::factory()->create();
        $invitee = User::factory()->create();
        $household = $this->household($creator);

        $this->actingAs($creator)
            ->postJson("/households/{$household->id}/members", ['user_id' => $invitee->id])
            ->assertCreated();

        $this->assertTrue($household->fresh()->isMember($invitee));
    }

    public function test_un_membre_non_createur_ne_peut_pas_inviter(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $target = User::factory()->create();
        $household = $this->household($creator, [$member]);

        $this->actingAs($member)
            ->postJson("/households/{$household->id}/members", ['user_id' => $target->id])
            ->assertForbidden();
    }

    public function test_le_createur_retire_un_membre_mais_pas_lui_meme(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($creator, [$member]);

        $this->actingAs($creator)
            ->deleteJson("/households/{$household->id}/members/{$member->id}")
            ->assertOk();
        $this->assertFalse($household->fresh()->isMember($member));

        // Le créateur ne peut pas se retirer via removeMember.
        $this->actingAs($creator)
            ->deleteJson("/households/{$household->id}/members/{$creator->id}")
            ->assertStatus(422);
        $this->assertTrue($household->fresh()->isMember($creator));
    }

    public function test_un_membre_quitte_le_foyer_mais_pas_le_createur(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($creator, [$member]);

        $this->actingAs($member)->postJson("/households/{$household->id}/leave")->assertOk();
        $this->assertFalse($household->fresh()->isMember($member));

        $this->actingAs($creator)->postJson("/households/{$household->id}/leave")->assertStatus(422);
        $this->assertTrue($household->fresh()->isMember($creator));
    }

    public function test_seul_le_createur_supprime_le_foyer(): void
    {
        $creator = User::factory()->create();
        $member = User::factory()->create();
        $household = $this->household($creator, [$member]);

        $this->actingAs($member)->deleteJson("/households/{$household->id}")->assertForbidden();

        $this->actingAs($creator)->deleteJson("/households/{$household->id}")->assertOk();
        $this->assertDatabaseMissing('households', ['id' => $household->id]);
    }
}
