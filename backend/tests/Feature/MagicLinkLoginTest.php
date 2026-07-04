<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MagicLinkLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('magic-link:family@example.com|127.0.0.1');
    }

    /**
     * Un utilisateur existant reçoit le lien par e-mail.
     */
    public function test_magic_link_is_sent_to_existing_user(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'family@example.com']);

        $response = $this->post('/login/magic', ['email' => 'family@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Notification::assertSentTo($user, MagicLoginLink::class);
    }

    /**
     * Une adresse inconnue reçoit la même réponse (pas d'énumération),
     * mais aucun e-mail ne part.
     */
    public function test_unknown_email_gets_same_response_without_notification(): void
    {
        Notification::fake();

        $response = $this->post('/login/magic', ['email' => 'inconnu@example.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Notification::assertNothingSent();
    }

    /**
     * Un lien signé valide connecte l'utilisateur avec une session longue durée.
     */
    public function test_valid_signed_link_logs_the_user_in(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute(
            'login.magic.verify',
            now()->addMinutes(30),
            ['user' => $user->id]
        );

        $response = $this->get($url);

        $response->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
    }

    /**
     * Un lien falsifié est rejeté.
     */
    public function test_tampered_link_is_rejected(): void
    {
        $user = User::factory()->create();

        $response = $this->get("/login/magic/{$user->id}?signature=falsifiee&expires=" . now()->addHour()->timestamp);

        $response->assertStatus(403);
        $this->assertGuest();
    }

    /**
     * Un lien expiré est rejeté.
     */
    public function test_expired_link_is_rejected(): void
    {
        $user = User::factory()->create();

        $url = URL::temporarySignedRoute(
            'login.magic.verify',
            now()->addMinutes(30),
            ['user' => $user->id]
        );

        $this->travel(31)->minutes();

        $response = $this->get($url);

        $response->assertStatus(403);
        $this->assertGuest();
    }

    /**
     * Les demandes sont limitées : 3 par adresse puis blocage temporaire.
     */
    public function test_magic_link_requests_are_rate_limited(): void
    {
        Notification::fake();

        User::factory()->create(['email' => 'family@example.com']);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login/magic', ['email' => 'family@example.com'])
                ->assertSessionHas('success');
        }

        $response = $this->post('/login/magic', ['email' => 'family@example.com']);

        $response->assertSessionHasErrors('email');
        Notification::assertCount(3);
    }
}
