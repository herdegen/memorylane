<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Album tourné à plusieurs endroits : étapes de la carte-trajet. */
class AlbumPlacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_places_are_grouped_and_in_trip_order(): void
    {
        $owner = User::factory()->create();
        $album = Album::factory()->create(['user_id' => $owner->id]);
        $clip = function (string $title, float $start, ?array $at) use ($owner, $album) {
            $m = Media::factory()->video()->create(['user_id' => $owner->id, 'title' => $title, 'taken_at' => '1957-08-01 10:00:00', 'clip_start' => $start]);
            if ($at) {
                $m->metadata()->create(['latitude' => $at[0], 'longitude' => $at[1]]);
            }
            $album->media()->attach($m->id, ['order' => 0]);

            return $m;
        };
        $carnac = $clip('Les alignements de Carnac', 1055, [47.584, -3.077]);
        $biarritz = $clip('Plage Royale à Biarritz', 203, [43.483, -1.559]);
        $hendaye = $clip('Fête à Hendaye', 12, [43.372, -1.774]);
        $biarritz2 = $clip('Rocher de la Vierge', 300, [43.487, -1.566]); // < 2 km : même étape
        $clip('Sans lieu', 500, null);

        $places = $this->actingAs($owner)->getJson("/albums/{$album->id}")->assertOk()->json('places');

        $this->assertSame([1, 2, 3], array_column($places, 'index'));
        $this->assertSame(['Fête à Hendaye', 'Plage Royale à Biarritz', 'Les alignements de Carnac'], array_column($places, 'label'));
        $this->assertSame([$biarritz->id, $biarritz2->id], $places[1]['media_ids']);
        $this->assertSame([$hendaye->id], $places[0]['media_ids']);
        $this->assertSame([$carnac->id], $places[2]['media_ids']);
    }

    public function test_an_album_in_one_place_has_a_single_stop(): void
    {
        $owner = User::factory()->create();
        $album = Album::factory()->create(['user_id' => $owner->id]);
        $m = Media::factory()->create(['user_id' => $owner->id]);
        $m->metadata()->create(['latitude' => 48.85, 'longitude' => 2.35]);
        $album->media()->attach($m->id, ['order' => 0]);

        $this->assertCount(1, $this->actingAs($owner)->getJson("/albums/{$album->id}")->json('places'));
    }
}
