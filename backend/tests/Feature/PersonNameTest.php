<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonNameTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Prénom + nom séparés : le nom d'affichage est composé automatiquement.
     */
    public function test_person_created_with_first_and_last_name_composes_display_name(): void
    {
        $response = $this->actingAs($this->user)->postJson('/people', [
            'first_name' => 'Jean-Marie',
            'last_name' => 'Dupont',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('people', [
            'first_name' => 'Jean-Marie',
            'last_name' => 'Dupont',
            'name' => 'Jean-Marie Dupont',
        ]);
    }

    /**
     * Compat : un `name` complet seul est découpé (dernier mot = nom).
     */
    public function test_full_name_is_split_into_first_and_last(): void
    {
        $response = $this->actingAs($this->user)->postJson('/people', [
            'name' => 'Marie Claire Fontaine',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('people', [
            'first_name' => 'Marie Claire',
            'last_name' => 'Fontaine',
            'name' => 'Marie Claire Fontaine',
        ]);
    }

    /**
     * Un seul mot = prénom seul (« Mamie »).
     */
    public function test_single_word_name_is_treated_as_first_name(): void
    {
        $response = $this->actingAs($this->user)->postJson('/people', [
            'name' => 'Mamie',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('people', [
            'first_name' => 'Mamie',
            'last_name' => null,
            'name' => 'Mamie',
        ]);
    }

    /**
     * Modifier le prénom recompose le nom d'affichage.
     */
    public function test_updating_first_name_recomposes_display_name(): void
    {
        $person = Person::factory()->create([
            'user_id' => $this->user->id,
            'first_name' => 'Jean',
            'last_name' => 'Dupont',
        ]);

        $response = $this->actingAs($this->user)->putJson("/people/{$person->id}", [
            'first_name' => 'Jeanne',
            'last_name' => 'Dupont',
        ]);

        $response->assertStatus(200);
        $this->assertEquals('Jeanne Dupont', $person->fresh()->name);
    }

    /**
     * Sans prénom ni nom complet, la création est refusée.
     */
    public function test_person_requires_a_name(): void
    {
        $response = $this->actingAs($this->user)->postJson('/people', [
            'gender' => 'F',
        ]);

        $response->assertStatus(422);
    }
}
