<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyChildTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['role' => 'user']);
        $this->otherUser = User::factory()->create(['role' => 'user']);
    }

    public function test_owner_adds_child_to_father(): void
    {
        $father = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $child = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->postJson("/people/{$father->id}/child", ['child_id' => $child->id])
            ->assertOk();

        $this->assertDatabaseHas('people', ['id' => $child->id, 'father_id' => $father->id]);
    }

    public function test_female_parent_fills_mother_slot(): void
    {
        $mother = Person::factory()->female()->create(['user_id' => $this->user->id]);
        $child = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->postJson("/people/{$mother->id}/child", ['child_id' => $child->id])
            ->assertOk();

        $this->assertDatabaseHas('people', ['id' => $child->id, 'mother_id' => $mother->id]);
    }

    public function test_other_parent_fills_complementary_slot(): void
    {
        $father = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $mother = Person::factory()->female()->create(['user_id' => $this->user->id]);
        $child = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->postJson("/people/{$father->id}/child", [
                'child_id' => $child->id,
                'other_parent_id' => $mother->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('people', [
            'id' => $child->id,
            'father_id' => $father->id,
            'mother_id' => $mother->id,
        ]);
    }

    public function test_cannot_be_own_child(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->postJson("/people/{$person->id}/child", ['child_id' => $person->id])
            ->assertStatus(422);
    }

    public function test_admin_can_add_child_to_others_person(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $father = Person::factory()->male()->create(['user_id' => $this->otherUser->id]);
        $child = Person::factory()->create(['user_id' => $this->otherUser->id]);

        $this->actingAs($admin)
            ->postJson("/people/{$father->id}/child", ['child_id' => $child->id])
            ->assertOk();

        $this->assertDatabaseHas('people', ['id' => $child->id, 'father_id' => $father->id]);
    }

    public function test_non_owner_non_admin_forbidden(): void
    {
        $father = Person::factory()->male()->create(['user_id' => $this->otherUser->id]);
        $child = Person::factory()->create(['user_id' => $this->otherUser->id]);

        $this->actingAs($this->user)
            ->postJson("/people/{$father->id}/child", ['child_id' => $child->id])
            ->assertStatus(403);
    }
}
