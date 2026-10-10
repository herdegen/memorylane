<?php

namespace Tests\Feature;

use App\Models\LifeEvent;
use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\PersonChangeReverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Arbre ouvert en écriture à tous les membres (hors invités et tablettes),
 * avec historique (person_changes) et annulation depuis le back-office.
 */
class FamilyTreeEditingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $member;
    protected Person $grandma;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->member = User::factory()->create(['role' => 'user']);
        $this->grandma = Person::factory()->create([
            'user_id' => $this->admin->id,
            'first_name' => 'Jeanne',
            'last_name' => 'MARTIN',
            'gender' => 'F',
            'birth_date' => '1940-05-02',
            'address' => null,
        ]);
    }

    public function test_member_can_edit_a_person_created_by_someone_else_and_it_is_logged(): void
    {
        $this->actingAs($this->member)
            ->putJson("/people/{$this->grandma->id}", [
                'first_name' => 'Jeanne',
                'last_name' => 'MARTIN',
                'birth_date' => '1941-05-02',
                'birth_place' => 'Lille',
            ])
            ->assertOk();

        $this->assertSame('1941-05-02', $this->grandma->fresh()->birth_date->toDateString());

        $change = PersonChange::where('person_id', $this->grandma->id)->where('action', 'updated')->firstOrFail();
        $this->assertSame($this->member->id, $change->user_id);
        $this->assertSame(['1940-05-02', '1941-05-02'], $change->diff['birth_date']);
        $this->assertSame([null, 'Lille'], $change->diff['birth_place']);
    }

    public function test_member_edit_does_not_wipe_a_hidden_address(): void
    {
        $this->grandma->forceFill(['address' => '1 rue des Lilas, Lille'])->saveQuietly();

        $this->actingAs($this->member)
            ->putJson("/people/{$this->grandma->id}", ['first_name' => 'Jeanne', 'last_name' => 'MARTIN', 'address' => ''])
            ->assertOk();

        $this->assertSame('1 rue des Lilas, Lille', $this->grandma->fresh()->address);
    }

    public function test_member_can_attach_a_forgotten_child_to_an_existing_person(): void
    {
        $this->actingAs($this->member);

        $baby = $this->postJson('/people', ['first_name' => 'Léo', 'last_name' => 'MARTIN', 'birth_date' => '2025-06-01'])
            ->assertCreated()
            ->json('person');

        $this->postJson("/people/{$this->grandma->id}/child", ['child_id' => $baby['id']])->assertOk();

        $this->assertSame($this->grandma->id, Person::find($baby['id'])->mother_id);
        $this->assertTrue(PersonChange::where('person_id', $baby['id'])->where('action', 'created')->exists());
        $this->assertTrue(PersonChange::where('person_id', $baby['id'])->where('action', 'updated')->exists());
    }

    public function test_spouse_changes_are_logged_on_both_people(): void
    {
        $grandpa = Person::factory()->create(['user_id' => $this->admin->id, 'gender' => 'M']);
        $this->actingAs($this->member);

        $this->postJson("/people/{$this->grandma->id}/spouse", ['spouse_id' => $grandpa->id])->assertOk();
        $this->assertSame(2, PersonChange::where('action', 'spouse_added')->count());

        $this->deleteJson("/people/{$this->grandma->id}/spouse", ['spouse_id' => $grandpa->id])->assertOk();
        $this->assertSame(2, PersonChange::where('action', 'spouse_removed')->count());
        $this->assertSame(0, PersonRelationship::count());
    }

    public function test_only_creator_or_admin_can_delete_a_person(): void
    {
        $this->actingAs($this->member)->deleteJson("/people/{$this->grandma->id}")->assertForbidden();

        $mine = Person::factory()->create(['user_id' => $this->member->id]);
        $this->actingAs($this->member)->deleteJson("/people/{$mine->id}")->assertOk();

        $this->actingAs($this->admin)->deleteJson("/people/{$this->grandma->id}")->assertOk();
    }

    public function test_guest_and_tablet_accounts_cannot_edit(): void
    {
        $guest = User::factory()->create(['guest_expires_at' => now()->addDay(), 'guest_person_id' => $this->grandma->id]);
        $this->actingAs($guest)
            ->putJson("/people/{$this->grandma->id}", ['first_name' => 'X'])
            ->assertForbidden();

        $tablet = User::factory()->create(['simple_mode' => true]);
        $this->actingAs($tablet)
            ->putJson("/people/{$this->grandma->id}", ['first_name' => 'X'])
            ->assertForbidden();

        $this->assertSame('Jeanne', $this->grandma->fresh()->first_name);
    }

    public function test_admin_can_revert_a_field_change(): void
    {
        $this->actingAs($this->member)
            ->putJson("/people/{$this->grandma->id}", ['first_name' => 'Jeannette', 'last_name' => 'MARTIN'])
            ->assertOk();

        $change = PersonChange::where('action', 'updated')->firstOrFail();
        $this->actingAs($this->admin);
        app(PersonChangeReverter::class)->revert($change, $this->admin);

        $this->assertSame('Jeanne', $this->grandma->fresh()->first_name);
        $this->assertNotNull($change->fresh()->reverted_at);
        $this->assertFalse($change->fresh()->isRevertible());
    }

    public function test_admin_can_revert_a_spouse_removal(): void
    {
        $grandpa = Person::factory()->create(['user_id' => $this->admin->id, 'gender' => 'M']);
        $ids = [$this->grandma->id, $grandpa->id];
        sort($ids);
        PersonRelationship::create(['person1_id' => $ids[0], 'person2_id' => $ids[1], 'type' => 'spouse']);

        $this->actingAs($this->member)
            ->deleteJson("/people/{$this->grandma->id}/spouse", ['spouse_id' => $grandpa->id])
            ->assertOk();

        $change = PersonChange::where('action', 'spouse_removed')->where('person_id', $this->grandma->id)->firstOrFail();
        $this->actingAs($this->admin);
        app(PersonChangeReverter::class)->revert($change, $this->admin);

        $this->assertSame(1, PersonRelationship::count());
        $this->assertSame(0, PersonChange::where('action', 'spouse_removed')->whereNull('reverted_at')->count());
    }

    public function test_tree_data_marks_people_editable_for_members(): void
    {
        $this->actingAs($this->member)
            ->getJson('/family-tree/data')
            ->assertOk()
            ->assertJsonPath('0.can_edit', true);
    }

    public function test_member_can_add_a_moment_but_only_its_author_can_delete_it(): void
    {
        $this->actingAs($this->member)
            ->postJson("/people/{$this->grandma->id}/events", ['title' => 'Mariage de Jeanne', 'event_date' => '1962-07-14'])
            ->assertSuccessful();

        $event = LifeEvent::firstOrFail();
        $other = User::factory()->create();
        $this->actingAs($other)->deleteJson("/life-events/{$event->id}")->assertForbidden();
        $this->actingAs($this->member)->deleteJson("/life-events/{$event->id}")->assertOk();
    }
}
