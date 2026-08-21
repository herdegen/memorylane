<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardOnThisDayTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Aucun appel réseau réel vers Nominis pendant les tests.
        Http::fake([
            'nominis.cef.fr/*' => Http::response(['response' => ['prenoms' => ['majeurs' => [], 'derives' => []]]]),
        ]);
    }

    /**
     * Les médias pris le même jour les années précédentes apparaissent,
     * groupés par année (plus récente d'abord).
     */
    public function test_dashboard_shows_memories_from_same_day_in_previous_years(): void
    {
        $threeYearsAgo = Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => now()->subYears(3)->setTime(14, 30),
        ]);
        $oneYearAgo = Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => now()->subYear()->setTime(10, 0),
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->has('onThisDay', 2)
            ->where('onThisDay.0.years_ago', 1)
            ->where('onThisDay.0.media.0.id', $oneYearAgo->id)
            ->where('onThisDay.1.years_ago', 3)
            ->where('onThisDay.1.media.0.id', $threeYearsAgo->id)
        );
    }

    /**
     * Un média pris un autre jour n'apparaît pas.
     */
    public function test_dashboard_excludes_media_from_other_days(): void
    {
        Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => now()->subYear()->subDays(10),
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('onThisDay', 0));
    }

    /**
     * Un média pris aujourd'hui (même année) n'apparaît pas : ce n'est pas
     * encore un souvenir.
     */
    public function test_dashboard_excludes_media_taken_today(): void
    {
        Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('onThisDay', 0));
    }

    /**
     * Les médias sans taken_at sont ignorés.
     */
    public function test_dashboard_ignores_media_without_taken_at(): void
    {
        Media::factory()->create([
            'user_id'  => $this->user->id,
            'taken_at' => null,
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->has('onThisDay', 0));
    }

    /**
     * Fêtes & anniversaires : anniversaire du jour + à venir dans la fenêtre,
     * anniversaire de mariage inclus, hors fenêtre exclu.
     */
    public function test_dashboard_celebrations_anniversaires_et_mariages(): void
    {
        $birthdayToday = Person::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Julien',
            'birth_date' => now()->subYears(32)->toDateString(),
        ]);
        Person::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Trop Loin',
            'birth_date' => now()->addDays(40)->subYears(20)->toDateString(),
        ]);
        $spouse1 = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Matthieu', 'birth_date' => null]);
        $spouse2 = Person::factory()->create(['user_id' => $this->user->id, 'name' => 'Marion', 'birth_date' => null]);
        \Illuminate\Support\Facades\DB::table('person_relationships')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'person1_id' => $spouse1->id,
            'person2_id' => $spouse2->id,
            'type' => 'spouse',
            'start_date' => now()->subYears(6)->addDays(3)->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $response->assertStatus(200);
        $celebrations = collect($response->viewData('page')['props']['celebrations']);

        $this->assertTrue($celebrations->contains(fn ($c) => $c['kind'] === 'birthday' && $c['title'] === 'Julien' && $c['days_until'] === 0));
        $this->assertTrue($celebrations->contains(fn ($c) => $c['kind'] === 'wedding' && str_contains($c['title'], 'Marion')));
        $this->assertFalse($celebrations->contains(fn ($c) => $c['title'] === 'Trop Loin'));
    }

    /**
     * La personne du jour n'apparaît QUE sans souvenir daté du jour.
     */
    public function test_personne_du_jour_est_le_repli_sans_souvenir(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $media = Media::factory()->create(['user_id' => $this->user->id, 'taken_at' => null]);
        $person->media()->attach($media->id);

        $response = $this->actingAs($this->user)->get('/dashboard');
        $response->assertInertia(fn ($page) => $page
            ->has('onThisDay', 0)
            ->where('personOfTheDay.id', $person->id));

        // Avec un souvenir du jour : pas de personne du jour.
        Media::factory()->create([
            'user_id' => $this->user->id,
            'taken_at' => now()->subYears(2),
        ]);
        $response = $this->actingAs($this->user)->get('/dashboard');
        $response->assertInertia(fn ($page) => $page
            ->has('onThisDay', 1)
            ->where('personOfTheDay', null));
    }

    /**
     * Le bloc « Bien démarrer » se masque PAR COMPTE, définitivement.
     */
    public function test_masquer_le_guide_est_persiste_par_compte(): void
    {
        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('showGuide', true));

        $this->actingAs($this->user)->postJson('/dashboard/hide-guide')->assertOk();

        $this->actingAs($this->user)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('showGuide', false));

        // Un autre compte n'est pas affecté.
        $other = User::factory()->create();
        $this->actingAs($other)->get('/dashboard')
            ->assertInertia(fn ($page) => $page->where('showGuide', true));
    }

    /**
     * Fête des prénoms : une personne vivante dont le prénom est au
     * calendrier du jour (accents ignorés) est célébrée.
     */
    public function test_fete_des_prenoms_du_jour(): void
    {
        // Cache préchargé (comme le ferait le cron) : pas d'appel réseau.
        \Illuminate\Support\Facades\Cache::put(
            sprintf('namedays:%02d-%02d', now()->month, now()->day),
            ['jeremie'],
            now()->addDay(),
        );

        Person::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Jeremie Dupont',
            'first_name' => 'Jeremie',
            'last_name' => 'Dupont',
        ]);

        $response = $this->actingAs($this->user)->get('/dashboard');

        $celebrations = collect($response->viewData('page')['props']['celebrations']);
        $this->assertTrue($celebrations->contains(fn ($c) => $c['kind'] === 'nameday' && $c['title'] === 'Jeremie Dupont'));
    }
}
