<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use App\Notifications\Push\BirthdaysToday;
use App\Notifications\Push\MemorialsToday;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Anniversaires de décès : cercle proche, disparitions de moins de 50 ans,
 * push distinct du push anniversaires + pastille sobre sur le tableau de bord.
 */
class MemorialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Person $me;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-07 09:00', 'Europe/Paris'));

        $this->user = User::factory()->create();
        $this->me = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Moi', 'birth_date' => null]);
        $this->user->forceFill(['person_id' => $this->me->id])->save();

        $this->user->updatePushSubscription('https://push.example.org/me', 'k', 't');
        $this->user->setPreference('push', ['social' => true, 'birthdays' => true, 'digest' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function diedYearsAgo(int $years, int $plusDays = 0): string
    {
        return today('Europe/Paris')->addDays($plusDays)->subYears($years)->toDateString();
    }

    public function test_un_grand_parent_disparu_ce_jour_declenche_un_push_sobre(): void
    {
        Notification::fake();
        $father = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null]);
        $grandpa = Person::factory()->create([
            'user_id' => $this->user->id, 'name' => 'Pépé Jean',
            'birth_date' => '1930-03-02', 'death_date' => $this->diedYearsAgo(10),
        ]);
        $this->me->update(['father_id' => $father->id]);
        $father->update(['father_id' => $grandpa->id]);

        $this->artisan('memorylane:birthday-push')->assertSuccessful();

        Notification::assertNotSentTo($this->user, BirthdaysToday::class);
        Notification::assertSentTo($this->user, MemorialsToday::class, function (MemorialsToday $n) use ($grandpa) {
            $data = $n->toWebPush($this->user, $n)->toArray();

            return $data['title'] === 'En mémoire'
                && $data['body'] === "Il y a 10 ans aujourd'hui, Pépé Jean nous quittait."
                && $data['data']['url'] === "/people/{$grandpa->id}";
        });
    }

    public function test_deces_trop_ancien_hors_cercle_ou_le_jour_meme_sont_exclus(): void
    {
        Notification::fake();
        $old = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null, 'death_date' => $this->diedYearsAgo(80)]);
        $justNow = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null, 'death_date' => $this->diedYearsAgo(0)]);
        $this->me->update(['father_id' => $old->id, 'mother_id' => $justNow->id]);
        // Hors cercle (aucun lien avec moi).
        Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null, 'death_date' => $this->diedYearsAgo(5)]);

        $this->artisan('memorylane:birthday-push')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_plusieurs_souvenirs_le_meme_jour(): void
    {
        $n = new MemorialsToday([
            ['id' => 'a', 'name' => 'Jean', 'years' => 10],
            ['id' => 'b', 'name' => 'Marie', 'years' => 1],
        ], '2026-10-07');

        $this->assertSame("Une pensée aujourd'hui pour Jean (10 ans) et Marie (1 an).", $n->sentence());
        $this->assertSame('/dashboard', $n->toWebPush($this->user, $n)->toArray()['data']['url']);
    }

    public function test_le_tableau_de_bord_affiche_le_souvenir_a_venir(): void
    {
        $mother = Person::factory()->create([
            'user_id' => $this->user->id, 'name' => 'Maman',
            'birth_date' => null, 'death_date' => $this->diedYearsAgo(3, 2),
        ]);
        $this->me->update(['mother_id' => $mother->id]);

        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page
                ->where('celebrations.0.kind', 'memorial')
                ->where('celebrations.0.title', 'Maman')
                ->where('celebrations.0.sub', 'En mémoire · 3 ans dans 2 jours'));
    }
}
