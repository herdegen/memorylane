<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'name' => 'Paul Herdegen',
            'email' => 'paul@example.com',
            'password' => Hash::make('ancien-mdp-8car'),
        ]);
    }

    public function test_profile_pages_require_auth(): void
    {
        $this->get('/profile')->assertRedirect('/login');
        $this->get('/profile/edit')->assertRedirect('/login');
    }

    public function test_can_view_and_edit_profile_pages(): void
    {
        $this->actingAs($this->user)->get('/profile')->assertStatus(200);
        $this->actingAs($this->user)->get('/profile/edit')->assertStatus(200);
    }

    public function test_can_update_name_and_email(): void
    {
        $this->actingAs($this->user)
            ->put('/profile', [
                'name' => 'Paul H.',
                'email' => 'nouveau@example.com',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->user->refresh();
        $this->assertEquals('Paul H.', $this->user->name);
        $this->assertEquals('nouveau@example.com', $this->user->email);
    }

    public function test_cannot_take_email_of_another_user(): void
    {
        User::factory()->create(['email' => 'pris@example.com']);

        $this->actingAs($this->user)
            ->put('/profile', [
                'name' => 'Paul',
                'email' => 'pris@example.com',
            ])
            ->assertSessionHasErrors(['email']);

        $this->assertEquals('paul@example.com', $this->user->fresh()->email);
    }

    public function test_update_cannot_mass_assign_role(): void
    {
        $this->actingAs($this->user)
            ->put('/profile', [
                'name' => 'Paul',
                'email' => 'paul@example.com',
                'role' => 'admin',
            ]);

        $this->assertNotEquals('admin', $this->user->fresh()->role);
    }

    public function test_password_update_requires_correct_current_password(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'mauvais-mdp',
                'password' => 'nouveau-mdp-8car',
                'password_confirmation' => 'nouveau-mdp-8car',
            ])
            ->assertSessionHasErrors(['current_password']);

        $this->assertTrue(Hash::check('ancien-mdp-8car', $this->user->fresh()->password));
    }

    public function test_password_update_requires_confirmation(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'ancien-mdp-8car',
                'password' => 'nouveau-mdp-8car',
                'password_confirmation' => 'autre-chose',
            ])
            ->assertSessionHasErrors(['password']);
    }

    public function test_can_update_password_with_correct_current_password(): void
    {
        $this->actingAs($this->user)
            ->put('/profile/password', [
                'current_password' => 'ancien-mdp-8car',
                'password' => 'nouveau-mdp-8car',
                'password_confirmation' => 'nouveau-mdp-8car',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('nouveau-mdp-8car', $this->user->fresh()->password));
    }
}
