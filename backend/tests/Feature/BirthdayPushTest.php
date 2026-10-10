<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Push\BirthdaysToday;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Push « anniversaires du jour » : cercle proche, personnes vivantes, un
 * seul push par jour, uniquement pour ceux qui l'ont activé.
 */
class BirthdayPushTest extends TestCase
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

    /** Date de naissance tombant aujourd'hui, il y a $age ans. */
    private function birthdayToday(int $age): string
    {
        return today('Europe/Paris')->subYears($age)->toDateString();
    }

    public function test_un_parent_dont_c_est_l_anniversaire_declenche_un_push_vers_sa_fiche(): void
    {
        Notification::fake();
        $father = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Papa', 'birth_date' => $this->birthdayToday(60)]);
        $this->me->update(['father_id' => $father->id]);

        $this->artisan('memorylane:birthday-push')->assertSuccessful();

        Notification::assertSentTo($this->user, BirthdaysToday::class, function (BirthdaysToday $n) use ($father) {
            $data = $n->toWebPush($this->user, $n)->toArray();

            return $data['data']['url'] === "/people/{$father->id}"
                && $data['body'] === "Aujourd'hui : anniversaire de Papa (60 ans)";
        });
    }

    public function test_plusieurs_anniversaires_font_un_seul_push_vers_le_tableau_de_bord(): void
    {
        Notification::fake();
        $father = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Papa', 'birth_date' => $this->birthdayToday(60)]);
        $mother = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Maman', 'birth_date' => $this->birthdayToday(58)]);
        $this->me->update(['father_id' => $father->id, 'mother_id' => $mother->id]);

        $this->artisan('memorylane:birthday-push')->assertSuccessful();

        Notification::assertSentToTimes($this->user, BirthdaysToday::class, 1);
        Notification::assertSentTo($this->user, BirthdaysToday::class, function (BirthdaysToday $n) {
            $data = $n->toWebPush($this->user, $n)->toArray();

            return $data['data']['url'] === '/dashboard'
                && str_contains($data['body'], 'Papa (60 ans)')
                && str_contains($data['body'], ' et de Maman (58 ans)');
        });
    }

    public function test_hors_du_cercle_proche_decede_ou_trop_age_sont_exclus(): void
    {
        Notification::fake();

        // Chaîne moi → père → grand-père → arrière-grand-père → arrière-arrière (distance 4).
        $father = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null]);
        $grand = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null]);
        $great = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => null]);
        $farAway = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Lointain', 'birth_date' => $this->birthdayToday(40)]);
        $this->me->update(['father_id' => $father->id]);
        $father->update(['father_id' => $grand->id]);
        $grand->update(['father_id' => $great->id]);
        $great->update(['father_id' => $farAway->id]);

        // Proches mais exclus : décédé, âge invraisemblable, anniversaire demain.
        $deceased = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => $this->birthdayToday(70), 'death_date' => '2020-01-01']);
        $ancient = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => $this->birthdayToday(120)]);
        $tomorrow = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => today('Europe/Paris')->addDay()->subYears(30)->toDateString()]);
        $this->me->update(['mother_id' => $deceased->id]);
        $deceased->update(['father_id' => $ancient->id, 'mother_id' => $tomorrow->id]);

        $this->artisan('memorylane:birthday-push')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_sans_fiche_moi_le_repli_est_les_personnes_de_mes_medias(): void
    {
        Notification::fake();
        $this->user->forceFill(['person_id' => null])->save();

        $onMyPhotos = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Tata', 'birth_date' => $this->birthdayToday(50)]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);
        $onMyPhotos->media()->attach($media->id);
        Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Inconnue', 'birth_date' => $this->birthdayToday(33)]);

        $this->artisan('memorylane:birthday-push')->assertSuccessful();

        Notification::assertSentTo($this->user, BirthdaysToday::class, function (BirthdaysToday $n) {
            return count($n->birthdays) === 1 && $n->birthdays[0]['name'] === 'Tata';
        });
    }

    public function test_pas_de_push_si_categorie_fermee_ou_sans_appareil(): void
    {
        Notification::fake();
        $father = Person::factory()->create(['user_id' => $this->user->id, 'birth_date' => $this->birthdayToday(60)]);
        $this->me->update(['father_id' => $father->id]);

        $this->user->setPreference('push.birthdays', false);
        $this->artisan('memorylane:birthday-push')->assertSuccessful();
        Notification::assertNothingSent();

        $this->user->setPreference('push.birthdays', true);
        $this->user->pushSubscriptions()->delete();
        $this->artisan('memorylane:birthday-push')->assertSuccessful();
        Notification::assertNothingSent();
    }

    public function test_au_dela_de_cinq_noms_le_message_resume(): void
    {
        $n = new BirthdaysToday(array_map(fn ($i) => ['id' => "p{$i}", 'name' => "P{$i}", 'age' => $i + 1], range(1, 7)), '2026-10-07');

        $this->assertSame(
            'de P1 (2 ans), de P2 (3 ans), de P3 (4 ans), de P4 (5 ans), de P5 (6 ans) et de 2 autres personnes',
            $n->peopleSentence(),
        );
        $this->assertSame('de Léo (1 an)', (new BirthdaysToday([['id' => 'x', 'name' => 'Léo', 'age' => 1]], '2026-10-07'))->peopleSentence());
    }
}
