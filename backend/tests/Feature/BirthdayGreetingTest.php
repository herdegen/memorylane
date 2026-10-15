<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fête d'anniversaire à la première visite du jour : même cercle proche que
 * le push du matin, une seule fois par jour et par compte.
 */
class BirthdayGreetingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Person $me;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00', 'Europe/Paris'));
        $this->user = User::factory()->create();
        $this->me = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Léa Martin', 'first_name' => 'Léa', 'birth_date' => '1990-03-01']);
        $this->user->forceFill(['person_id' => $this->me->id])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function birthdayToday(int $age): string
    {
        return today('Europe/Paris')->subYears($age)->toDateString();
    }

    public function test_a_close_relative_s_birthday_is_celebrated_once_a_day(): void
    {
        $father = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Paul Martin', 'first_name' => 'Paul', 'birth_date' => $this->birthdayToday(60)]);
        $this->me->update(['father_id' => $father->id]);

        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('birthdayGreeting.self', null)
                ->where('birthdayGreeting.people.0.id', $father->id)
                ->where('birthdayGreeting.people.0.first_name', 'Paul')
                ->where('birthdayGreeting.people.0.age', 60));

        // Vue : plus rien aujourd'hui, sur aucun appareil ; le lendemain, plus d'anniversaire.
        $this->actingAs($this->user)->post('/celebrations/birthday-seen')->assertNoContent();
        $this->actingAs($this->user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('birthdayGreeting', null));

        Carbon::setTestNow(Carbon::parse('2026-10-08 09:00', 'Europe/Paris'));
        $this->actingAs($this->user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('birthdayGreeting', null));
    }

    public function test_one_s_own_birthday(): void
    {
        $this->me->update(['birth_date' => $this->birthdayToday(36)]);

        $this->actingAs($this->user)->get('/albums')
            ->assertInertia(fn ($page) => $page
                ->where('birthdayGreeting.self.first_name', 'Léa')
                ->where('birthdayGreeting.self.age', 36)
                ->where('birthdayGreeting.people', []));
    }

    public function test_nothing_without_a_birthday_or_while_impersonating(): void
    {
        $this->actingAs($this->user)->get('/dashboard')->assertInertia(fn ($page) => $page->where('birthdayGreeting', null));

        $this->me->update(['birth_date' => $this->birthdayToday(36)]);
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        app(\App\Services\Impersonation::class)->start($admin, $this->user);
        $this->get('/dashboard')->assertInertia(fn ($page) => $page->where('birthdayGreeting', null));
    }
}
