<?php

namespace Tests\Feature;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\RecapService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Récaps « Prénom au fil des ans » : qui en a un, quelles photos, à la une. */
class RecapTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(string $first, array $extra = []): Person
    {
        return Person::factory()->create(['user_id' => $this->me->id, 'first_name' => $first, 'name' => "{$first} Test"] + $extra);
    }

    /** Photo où la personne est reconnue (visage de taille $area %², $others autres visages). */
    private function photo(Person $p, string $takenAt, float $side = 10, int $others = 0, ?User $owner = null, ?string $ficheStatus = null): Media
    {
        $m = Media::factory()->photo()->create(['user_id' => ($owner ?? $this->me)->id, 'taken_at' => $takenAt]);
        $m->people()->attach($p->id, $ficheStatus ? ['fiche_status' => $ficheStatus] : []);
        DetectedFace::create(['media_id' => $m->id, 'person_id' => $p->id, 'status' => 'matched', 'provider' => 'insightface',
            'confidence' => 0.9, 'bounding_box' => ['x' => 10, 'y' => 10, 'width' => $side, 'height' => $side]]);
        foreach (range(1, $others) as $i) {
            if ($others === 0) {
                break;
            }
            DetectedFace::create(['media_id' => $m->id, 'status' => 'unmatched', 'provider' => 'insightface',
                'confidence' => 0.9, 'bounding_box' => ['x' => 50, 'y' => 10, 'width' => 5, 'height' => 5]]);
        }

        return $m;
    }

    private function service(): RecapService
    {
        return app(RecapService::class);
    }

    public function test_un_recap_a_partir_de_cinq_annees_de_photos_visibles(): void
    {
        $loic = $this->person('Loïc', ['birth_date' => '1988-03-01']);
        $paul = $this->person('Paul', ['birth_date' => '1990-01-01']);
        foreach ([2008, 2010, 2012, 2017, 2020] as $y) {
            $this->photo($loic, "{$y}-07-01 12:00:00");
        }
        foreach ([2008, 2010, 2012, 2017] as $y) {
            $this->photo($paul, "{$y}-07-01 12:00:00");
        }
        // Une 5e année pour Paul, mais sur une photo que je ne vois pas…
        $this->photo($paul, '2020-07-01 12:00:00', owner: User::factory()->create());
        // … et une autre qu'il a gardée hors de sa fiche.
        $this->photo($paul, '2021-07-01 12:00:00', ficheStatus: 'private');
        // … et un document qui le cite, identifié sur sa fiche mais sans son visage.
        $document = Media::factory()->photo()->create(['user_id' => $this->me->id, 'taken_at' => '1926-01-01 12:00:00']);
        $document->people()->attach($paul->id);

        $people = $this->service()->throughYearsPeople($this->me);
        $this->assertSame(['Loïc'], $people->map(fn ($r) => $r['person']->first_name)->all());
        $this->assertSame(5, $people[0]['years']);
    }

    public function test_une_photo_par_annee_la_plus_belle_avec_l_age(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $loic = $this->person('Loïc', ['birth_date' => '1988-03-01']);
        $group = $this->photo($loic, '2008-07-01 12:00:00', side: 12, others: 9);
        $portrait = $this->photo($loic, '2008-08-01 12:00:00', side: 8);
        foreach ([2010, 2012, 2017, 2020] as $y) {
            $this->photo($loic, "{$y}-07-01 12:00:00");
        }

        $recap = $this->service()->throughYears($this->me, $loic);
        $photos = array_values(array_filter($recap['slides'], fn ($s) => $s['type'] === 'photo'));
        $this->assertCount(5, $photos);
        // 2008 : le portrait l'emporte sur la photo de groupe
        $this->assertSame($portrait->id, $photos[0]['media_id']);
        $this->assertSame('2008', $photos[0]['chapter']);
        $this->assertSame('20 ans', $photos[0]['label']);
        $this->assertSame('Loïc au fil des ans', $recap['slides'][0]['card']['title']);
        $this->assertSame('2008 → 2020', $recap['slides'][0]['card']['date']);
        $this->assertSame('✨', end($recap['slides'])['card']['icon']);
        $this->assertCount(5, $recap['media']);
        $this->assertNotContains($group->id, $recap['media']->pluck('id')->all());
    }

    public function test_a_la_une_le_jour_de_l_anniversaire_et_les_jours_suivants(): void
    {
        $loic = $this->person('Loïc', ['birth_date' => '1988-11-09']);
        foreach ([2008, 2010, 2012, 2017, 2020] as $y) {
            $this->photo($loic, "{$y}-07-01 12:00:00");
        }

        Carbon::setTestNow('2026-11-09 09:00:00');
        $featured = $this->service()->featured($this->me);
        $this->assertSame(['Loïc au fil des ans'], array_column($featured, 'title'));
        $this->assertSame('years:'.$loic->id.':2026', $featured[0]['key']);
        $this->assertFalse($featured[0]['seen']);
        $outro = end($this->service()->throughYears($this->me, $loic)['slides']);
        $this->assertSame('🎂', $outro['card']['icon']);
        $this->assertSame('38 ans aujourd’hui', $outro['card']['description']);

        // Vu : plus de pastille
        $this->actingAs($this->me)->postJson('/recaps/seen', ['key' => $featured[0]['key']])->assertOk();
        $this->assertTrue($this->service()->featured($this->me)[0]['seen']);

        Carbon::setTestNow('2026-11-12 09:00:00');
        $this->assertCount(1, $this->service()->featured($this->me));
        Carbon::setTestNow('2026-11-13 09:00:00');
        $this->assertCount(0, $this->service()->featured($this->me));
    }

    public function test_pages_et_acces(): void
    {
        $loic = $this->person('Loïc', ['birth_date' => '1988-03-01']);
        foreach ([2008, 2010, 2012, 2017, 2020] as $y) {
            $this->photo($loic, "{$y}-07-01 12:00:00");
        }

        $this->actingAs($this->me)->get('/recaps')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Recaps/Index', false)->where('people.0.title', 'Loïc au fil des ans'));
        $this->actingAs($this->me)->getJson("/recaps/years/{$loic->id}")->assertOk()->assertJsonCount(7, 'slides');
        // Une fiche que je ne vois pas : rien
        $this->actingAs(User::factory()->create())->getJson("/recaps/years/{$loic->id}")->assertNotFound();
        $this->actingAs($this->me)->postJson('/recaps/seen', ['key' => '../../etc'])->assertUnprocessable();
        $this->actingAs($this->me)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->has('recaps'));
        $this->assertSame(0, DB::table('recap_views')->count());
    }

    /** $n photos ajoutées dans l'archive le $addedAt, prises en $takenYear. */
    private function added(int $n, string $addedAt, int $takenYear, ?Person $with = null): array
    {
        return collect(range(1, $n))->map(function ($i) use ($addedAt, $takenYear, $with) {
            $m = Media::factory()->photo()->create(['user_id' => $this->me->id,
                'taken_at' => sprintf('%d-06-%02d 12:00:00', $takenYear, ($i % 28) + 1), 'created_at' => $addedAt]);
            if ($with) {
                $m->people()->attach($with->id);
                DetectedFace::create(['media_id' => $m->id, 'person_id' => $with->id, 'status' => 'matched', 'provider' => 'insightface',
                    'confidence' => 0.9, 'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 10, 'height' => 10]]);
            }

            return $m;
        })->all();
    }

    public function test_l_annee_ce_qui_est_entre_dans_l_archive(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $marianne = $this->person('Marianne', ['birth_date' => '1931-01-22']);
        $this->added(20, '2026-03-01 10:00:00', 2010, $marianne);
        $this->added(10, '2026-07-01 10:00:00', 1984);
        $old = $this->added(2, '2026-10-01 10:00:00', 1920);
        // Trop peu d'ajouts en 2025 : pas de récap
        $this->added(5, '2025-05-01 10:00:00', 2000);

        $cards = $this->service()->yearCards($this->me);
        $this->assertSame([2026], array_column($cards, 'year'));
        $this->assertSame('en cours', $cards[0]['subtitle']);

        $recap = $this->service()->year($this->me, 2026);
        $intro = $recap['slides'][0]['card'];
        $this->assertSame('L’année 2026 dans la mémoire familiale', $intro['title']);
        $this->assertStringContainsString('32 photos et vidéos ajoutées', $intro['description']);
        $this->assertStringContainsString('20 visages reconnus', $intro['description']);
        $chapters = array_column(array_filter($recap['slides'], fn ($s) => $s['type'] === 'photo'), 'chapter');
        // Redécouvertes (les 3 plus anciennes : 1920, 1920, 1984), puis un
        // moment par décennie pas encore montrée
        $this->assertSame(['Redécouvert', 'Redécouvert', 'Redécouvert', 'Années 2010'], array_values($chapters));
        $this->assertContains($old[0]->id, $recap['media']->pluck('id')->all());
        $titles = array_column(array_column(array_filter($recap['slides'], fn ($s) => $s['type'] === 'card'), 'card'), 'title');
        $this->assertContains('Marianne', $titles);
        $this->assertSame('Merci !', end($titles));
        $this->assertNull($this->service()->year($this->me, 2025));

        // À la une seulement en fin d'année (et en janvier)
        $this->assertSame([], array_column($this->service()->featured($this->me), 'key'));
        Carbon::setTestNow('2026-12-20 10:00:00');
        $this->assertSame(['year:2026'], array_column($this->service()->featured($this->me), 'key'));
        Carbon::setTestNow('2027-01-15 10:00:00');
        $this->assertSame(['year:2026'], array_column($this->service()->featured($this->me), 'key'));

        $this->actingAs($this->me)->getJson('/recaps/year/2026')->assertOk();
        $this->actingAs($this->me)->getJson('/recaps/year/2025')->assertNotFound();
        $this->actingAs(User::factory()->create())->getJson('/recaps/year/2026')->assertNotFound();
    }
}
