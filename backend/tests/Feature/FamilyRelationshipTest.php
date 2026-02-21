<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyRelationshipTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_can_set_father(): void
    {
        $father = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $child = Person::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/people/{$child->id}/parent", [
            'parent_id' => $father->id,
            'parent_type' => 'father',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('people', [
            'id' => $child->id,
            'father_id' => $father->id,
        ]);
    }

    public function test_can_set_mother(): void
    {
        $mother = Person::factory()->female()->create(['user_id' => $this->user->id]);
        $child = Person::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/people/{$child->id}/parent", [
            'parent_id' => $mother->id,
            'parent_type' => 'mother',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('people', [
            'id' => $child->id,
            'mother_id' => $mother->id,
        ]);
    }

    public function test_cannot_set_self_as_parent(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/people/{$person->id}/parent", [
            'parent_id' => $person->id,
            'parent_type' => 'father',
        ]);

        $response->assertStatus(422);
    }

    public function test_can_remove_parent(): void
    {
        $father = Person::factory()->create(['user_id' => $this->user->id]);
        $child = Person::factory()->create([
            'user_id' => $this->user->id,
            'father_id' => $father->id,
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/people/{$child->id}/parent", [
            'parent_type' => 'father',
        ]);

        $response->assertStatus(200);
        $child->refresh();
        $this->assertNull($child->father_id);
    }

    public function test_can_add_spouse(): void
    {
        $person1 = Person::factory()->create(['user_id' => $this->user->id]);
        $person2 = Person::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/people/{$person1->id}/spouse", [
            'spouse_id' => $person2->id,
            'type' => 'spouse',
            'start_date' => '1990-06-15',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('person_relationships', [
            'type' => 'spouse',
        ]);
    }

    public function test_cannot_add_self_as_spouse(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson("/people/{$person->id}/spouse", [
            'spouse_id' => $person->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_can_remove_spouse(): void
    {
        $person1 = Person::factory()->create(['user_id' => $this->user->id]);
        $person2 = Person::factory()->create(['user_id' => $this->user->id]);

        $ids = [$person1->id, $person2->id];
        sort($ids);
        PersonRelationship::create([
            'person1_id' => $ids[0],
            'person2_id' => $ids[1],
            'type' => 'spouse',
        ]);

        $response = $this->actingAs($this->user)->deleteJson("/people/{$person1->id}/spouse", [
            'spouse_id' => $person2->id,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('person_relationships', [
            'person1_id' => $ids[0],
            'person2_id' => $ids[1],
        ]);
    }

    public function test_cannot_set_parent_on_other_users_person(): void
    {
        $otherUser = User::factory()->create();
        $parent = Person::factory()->create(['user_id' => $this->user->id]);
        $child = Person::factory()->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->postJson("/people/{$child->id}/parent", [
            'parent_id' => $parent->id,
            'parent_type' => 'father',
        ]);

        $response->assertStatus(403);
    }

    public function test_tree_data_returns_only_own_people(): void
    {
        Person::factory()->count(3)->create(['user_id' => $this->user->id]);
        $otherUser = User::factory()->create();
        Person::factory()->count(2)->create(['user_id' => $otherUser->id]);

        $response = $this->actingAs($this->user)->getJson('/family-tree/data');

        $response->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function test_tree_data_includes_relationships(): void
    {
        $father = Person::factory()->male()->create(['user_id' => $this->user->id]);
        $mother = Person::factory()->female()->create(['user_id' => $this->user->id]);
        $child = Person::factory()->create([
            'user_id' => $this->user->id,
            'father_id' => $father->id,
            'mother_id' => $mother->id,
        ]);

        $response = $this->actingAs($this->user)->getJson('/family-tree/data');

        $response->assertStatus(200);

        $data = $response->json();
        $childNode = collect($data)->firstWhere('id', $child->id);
        $this->assertEquals($father->id, $childNode['rels']['father']);
        $this->assertEquals($mother->id, $childNode['rels']['mother']);
    }

    public function test_tree_page_loads(): void
    {
        $response = $this->actingAs($this->user)->get('/family-tree');
        $response->assertStatus(200);
    }

    public function test_unauthenticated_cannot_access_tree(): void
    {
        $response = $this->getJson('/family-tree/data');
        $response->assertStatus(401);
    }
}
