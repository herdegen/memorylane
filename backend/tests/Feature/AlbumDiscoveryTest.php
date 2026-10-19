<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page Albums vivante : photos tirées au sort par album, album à l'honneur
 * (anniversaire ce mois-ci en priorité), décennies.
 */
class AlbumDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_apercus_honneur_et_decennies(): void
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
                // 6 photos au plus par album
                ->where('albums', fn ($albums) => count(collect($albums)->firstWhere('id', $old->id)['previews']) === 6
                    && count(collect($albums)->firstWhere('id', $recent->id)['previews']) === 3)
                ->where('discovery.decades', fn ($d) => collect($d)->pluck('decade')->count() >= 1));
    }
}
