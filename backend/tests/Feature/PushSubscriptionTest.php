<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Push\TestPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Notifications push : abonnement par appareil, préférences, test, carte.
 */
class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create(['preferences' => ['share_address_with_household' => true]]);
    }

    private function payload(string $endpoint = 'https://push.example.org/sub/abc'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => 'BPubKey', 'auth' => 'authSecret'],
            'content_encoding' => 'aes128gcm',
        ];
    }

    public function test_les_routes_exigent_une_session(): void
    {
        $this->postJson('/push/subscriptions', $this->payload())->assertUnauthorized();
        $this->putJson('/push/preferences', [])->assertUnauthorized();
    }

    public function test_abonnement_cree_une_ligne_liee_a_l_utilisateur_uuid(): void
    {
        $this->actingAs($this->user)
            ->postJson('/push/subscriptions', $this->payload(), ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'])
            ->assertCreated()
            ->assertJsonPath('subscriptions.0.label', 'iPhone · Safari')
            ->assertJsonPath('preferences.social', true);

        $sub = PushSubscription::firstOrFail();
        $this->assertSame($this->user->id, (string) $sub->subscribable_id);
        $this->assertSame(User::class, $sub->subscribable_type);
        $this->assertSame('BPubKey', $sub->public_key);
        $this->assertStringContainsString('iPhone', $sub->user_agent);
    }

    public function test_premiere_activation_ouvre_toutes_les_categories_sans_ecraser_les_autres_preferences(): void
    {
        $this->actingAs($this->user)->postJson('/push/subscriptions', $this->payload())->assertCreated();

        $this->user->refresh();
        $this->assertTrue($this->user->sharesAddressWithHousehold());
        $this->assertSame(['social' => true, 'birthdays' => true, 'digest' => true], $this->user->pushPreferences());
        $this->assertTrue($this->user->wantsPush('social'));
    }

    public function test_abonnement_idempotent_par_endpoint_et_ne_rouvre_pas_une_categorie_fermee(): void
    {
        $this->actingAs($this->user)->postJson('/push/subscriptions', $this->payload())->assertCreated();
        $this->user->refresh()->setPreference('push.social', false);

        $again = $this->payload();
        $again['keys']['p256dh'] = 'BNewKey';
        $this->actingAs($this->user)->postJson('/push/subscriptions', $again)->assertCreated();

        $this->assertSame(1, PushSubscription::count());
        $this->assertSame('BNewKey', PushSubscription::first()->public_key);
        $this->assertFalse($this->user->refresh()->pushPreferences()['social']);
    }

    public function test_sans_abonnement_aucune_categorie_n_envoie(): void
    {
        $this->user->setPreference('push', ['social' => true]);
        $this->assertFalse($this->user->wantsPush('social'));
    }

    public function test_retirer_un_appareil_le_sien_seulement(): void
    {
        $other = User::factory()->create();
        $mine = $this->user->updatePushSubscription('https://push.example.org/mine', 'k', 't');
        $theirs = $other->updatePushSubscription('https://push.example.org/theirs', 'k', 't');

        $this->actingAs($this->user)->deleteJson("/push/subscriptions/{$theirs->id}")->assertNotFound();
        $this->actingAs($this->user)->deleteJson("/push/subscriptions/{$mine->id}")
            ->assertOk()
            ->assertJsonCount(0, 'subscriptions');

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $mine->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $theirs->id]);
    }

    public function test_preferences_fusionnent_et_conservent_la_fermeture_de_la_carte(): void
    {
        $this->user->setPreference('push', ['social' => true, 'birthdays' => true, 'digest' => true, 'prompt_dismissed_at' => '2026-10-01T10:00:00+00:00']);

        $this->actingAs($this->user)
            ->putJson('/push/preferences', ['social' => true, 'birthdays' => false, 'digest' => true])
            ->assertOk()
            ->assertJsonPath('preferences.birthdays', false);

        $stored = $this->user->refresh()->preferences['push'];
        $this->assertFalse($stored['birthdays']);
        $this->assertSame('2026-10-01T10:00:00+00:00', $stored['prompt_dismissed_at']);

        $this->actingAs($this->user)
            ->putJson('/push/preferences', ['social' => 'oui', 'birthdays' => true, 'digest' => true])
            ->assertUnprocessable();
    }

    public function test_notification_de_test_envoyee_seulement_avec_un_appareil(): void
    {
        Notification::fake();

        $this->actingAs($this->user)->postJson('/push/test')->assertUnprocessable();
        Notification::assertNothingSent();

        $this->user->updatePushSubscription('https://push.example.org/mine', 'k', 't');
        $this->actingAs($this->user)->postJson('/push/test')->assertOk();
        Notification::assertSentTo($this->user, TestPush::class);
    }

    public function test_fermer_la_carte_du_tableau_de_bord(): void
    {
        $this->actingAs($this->user)->postJson('/push/prompt-dismiss')->assertOk();
        $this->assertNotNull($this->user->refresh()->preferences['push']['prompt_dismissed_at']);
    }

    public function test_la_page_profil_expose_la_cle_vapid_et_les_appareils(): void
    {
        config(['webpush.vapid.public_key' => 'BTestKey']);
        $this->user->updatePushSubscription('https://push.example.org/mine', 'k', 't');

        $this->actingAs($this->user)->get('/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('push.vapid_public_key', 'BTestKey')
                ->where('push.has_subscriptions', true)
                ->has('pushSubscriptions', 1)
                ->missing('pushSubscriptions.0.auth_token'));
    }
}
