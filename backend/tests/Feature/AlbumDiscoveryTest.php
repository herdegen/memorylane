<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page Albums : photos tirées au sort par album, album à l'honneur
 * (anniversaire ce mois-ci en priorité), place de chaque album dans la frise.
 */
class AlbumDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_apercus_et_album_a_l_honneur(): void
    {
        $me = User::factory()->create();
        $old = Album::factory()->create(['user_id' => $me->id, 'name' => 'Il y a longtemps']);
        $recent = Album::factory()->create(['user_id' => $me->id, 'name' => 'Récent']);
        foreach (range(1, 8) as $i) {
            $m = Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => now()->subYears(10)->startOfMonth()->addDays($i)]);
            $old->media()->attach($m->id);
        }
        foreach (range(1, 3) as $i) {
            $m = Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => now()->subMonths(3)]);
            $recent->media()->attach($m->id);
        }

        $this->actingAs($me)->get('/albums')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Albums/Index', false)
                // Anniversaire du mois en priorité
                ->where('discovery.featured.id', $old->id)
                ->where('discovery.featured.reason', 'Il y a 10 ans ce mois-ci')
                // La page web n'a plus de rangées : chaque album n'y figure qu'une fois
                ->where('discovery.rows', [])
                // 6 photos au plus par album
                ->where('albums', fn ($albums) => count(collect($albums)->firstWhere('id', $old->id)['previews']) === 6
                    && count(collect($albums)->firstWhere('id', $recent->id)['previews']) === 3));
    }

    public function test_place_dans_la_frise_date_de_l_album_sinon_date_mediane(): void
    {
        $me = User::factory()->create();
        // Photos surtout de 2009, une de 2018 : l'album reste en 2009.
        $mostly2009 = Album::factory()->create(['user_id' => $me->id]);
        foreach (['2009-08-26', '2009-08-27', '2009-08-28', '2018-07-12'] as $d) {
            $mostly2009->media()->attach(Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => $d])->id);
        }
        // Une photo sans date ne compte pas.
        $mostly2009->media()->attach(Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => null])->id);
        // La date de l'album l'emporte sur celle des photos.
        $dated = Album::factory()->create(['user_id' => $me->id, 'date' => '1984-04-15']);
        $dated->media()->attach(Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => '2001-01-01'])->id);
        // Sans aucune date : nulle part dans la frise.
        $undated = Album::factory()->create(['user_id' => $me->id]);
        $undated->media()->attach(Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => null])->id);

        $this->actingAs($me)->get('/albums')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('albums', function ($albums) use ($mostly2009, $dated, $undated) {
                $by = collect($albums)->keyBy('id');

                return str_starts_with($by[$mostly2009->id]['placed_at'], '2009-08')
                    && $by[$mostly2009->id]['last_at'] === '2018-07-12'
                    && $by[$dated->id]['placed_at'] === '1984-04-15'
                    && $by[$undated->id]['placed_at'] === null;
            }));
    }
}
