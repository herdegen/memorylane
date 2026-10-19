<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Cycle de vie d'un compte : la base protège ce qu'il possède (plus
 * d'effacement en cascade), compte « en mémoire » (membre décédé).
 */
class AccountLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_supprimer_un_compte_qui_possede_des_donnees_est_refuse(): void
    {
        foreach ([
            fn (User $u) => Media::factory()->photo()->create(['user_id' => $u->id]),
            fn (User $u) => Album::factory()->create(['user_id' => $u->id]),
            fn (User $u) => Person::factory()->create(['user_id' => $u->id]),
        ] as $own) {
            $user = User::factory()->create();
            $own($user);
            try {
                // Point de sauvegarde : PostgreSQL bloque sinon la transaction du test.
                \Illuminate\Support\Facades\DB::transaction(fn () => $user->delete());
                $this->fail('suppression acceptée alors que le compte possède des données');
            } catch (QueryException $e) {
                $this->assertDatabaseHas('users', ['id' => $user->id]);
            }
        }

        // Sans rien posséder : la suppression passe.
        $empty = User::factory()->create();
        $empty->delete();
        $this->assertDatabaseMissing('users', ['id' => $empty->id]);
    }

    public function test_compte_en_memoire_ne_se_connecte_plus(): void
    {
        $user = User::factory()->create(['email' => 'memoire@example.com', 'password' => 'secret-123']);
        $user->markMemorial();
        RateLimiter::clear('login:memoire@example.com|127.0.0.1');
        RateLimiter::clear('magic-link:memoire@example.com|127.0.0.1');

        // Mot de passe
        $this->post('/login', ['email' => 'memoire@example.com', 'password' => 'secret-123'])->assertSessionHasErrors('email');
        $this->assertGuest();

        // Lien magique : rien n'est envoyé
        Notification::fake();
        $this->post('/login/magic', ['email' => 'memoire@example.com']);
        Notification::assertNothingSentTo($user);

        // Session déjà ouverte : fermée à la requête suivante
        $this->actingAs($user->fresh())->get('/dashboard')->assertRedirect('/login');
    }

    public function test_compte_en_memoire_garde_tout_et_sort_des_listes(): void
    {
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id]);
        $user->update(['person_id' => $person->id]);
        $photo = Media::factory()->photo()->create(['user_id' => $user->id]);

        $user->markMemorial();

        $this->assertDatabaseHas('media', ['id' => $photo->id]);
        $this->assertFalse(User::realPeople()->whereKey($user->id)->exists(), 'plus de notification, d\'accès ni d\'invitation');

        $viewer = User::factory()->admin()->create();
        $this->actingAs($viewer)->get("/people/{$person->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Show', false)->where('account.memorial', true));

        $user->unmarkMemorial();
        $this->assertTrue(User::realPeople()->whereKey($user->id)->exists());
    }
}
