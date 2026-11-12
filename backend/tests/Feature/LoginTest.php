<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_displayed(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_with_correct_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect('/dashboard');
    }

    /**
     * L'adresse est retrouvée quelle que soit la casse : enregistrée avec une
     * majuscule, saisie en minuscules (ou l'inverse), la connexion passe.
     */
    public function test_users_can_authenticate_whatever_the_email_case(): void
    {
        $user = User::factory()->create([
            'email' => 'Jean.Dupont@Example.com',
            'password' => bcrypt('password123'),
        ]);
        $this->assertSame('jean.dupont@example.com', $user->fresh()->email);

        $this->post('/login', ['email' => ' jean.dupont@example.com ', 'password' => 'password123']);
        $this->assertAuthenticatedAs($user);

        auth()->logout();
        $this->post('/login', ['email' => 'JEAN.dupont@example.COM', 'password' => 'password123']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_users_cannot_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email', 'password']);
    }

    public function test_users_cannot_authenticate_with_invalid_email(): void
    {
        $response = $this->post('/login', [
            'email' => 'wrong@example.com',
            'password' => 'password123',
        ]);

        $this->assertGuest();
        $response->assertSessionHasErrors(['email', 'password']);
    }
}
