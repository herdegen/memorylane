<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Séparateurs par année dans la galerie (issue #40) : le tri serveur doit
 * mettre les médias sans date EN FIN (section « Sans date » en dernier).
 * Sous Postgres, ORDER BY taken_at DESC met les NULLs en premier par défaut —
 * d'où le NULLS LAST explicite dans MediaService::buildFilteredQuery.
 */
class GalleryYearSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_medias_sans_date_arrivent_en_dernier(): void
    {
        $user = User::factory()->create();

        $undated = Media::factory()->photo()->create(['user_id' => $user->id, 'taken_at' => null]);
        $old = Media::factory()->photo()->create(['user_id' => $user->id, 'taken_at' => now()->subYears(2)]);
        $recent = Media::factory()->photo()->create(['user_id' => $user->id, 'taken_at' => now()->subDay()]);

        $response = $this->actingAs($user)->getJson('/media');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertSame([$recent->id, $old->id, $undated->id], $ids);
    }
}
