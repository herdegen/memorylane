<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Vision\FaceMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Page d'une photo : suggestions d'un visage = fiches du cercle courant +
 * personnes déjà identifiées dans un album de la photo (même d'un autre cercle).
 */
class FaceSuggestionContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_du_cercle_et_des_presents_sur_l_album(): void
    {
        $me = User::factory()->create();
        $family = Household::factory()->create();
        $friends = Household::factory()->friends()->create();
        $family->members()->attach($me->id);
        $friends->members()->attach($me->id);
        $me->forceFill(['current_household_id' => $friends->id])->save();

        $lea = Person::factory()->create(['name' => 'Léa Amie', 'in_family_directory' => false]);
        $lea->circles()->attach($friends->id);
        $paul = Person::factory()->create(['name' => 'Paul Cousin', 'in_family_directory' => true]);   // famille, sur l'album
        $zoe = Person::factory()->create(['name' => 'Zoé Tante', 'in_family_directory' => true]);      // famille, absente

        $album = Album::factory()->create(['user_id' => $me->id]);
        $photo = Media::factory()->photo()->create(['user_id' => $me->id]);
        $other = Media::factory()->photo()->create(['user_id' => $me->id]);
        $album->media()->attach([$photo->id, $other->id]);
        $other->people()->attach($paul->id);
        $face = $photo->detectedFaces()->create([
            'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
            'status' => 'unmatched',
            'provider' => 'faceapi',
        ]);

        $this->partialMock(FaceMatcher::class, fn ($m) => $m->shouldReceive('rankedCandidates')->andReturn([
            ['person' => ['id' => $zoe->id, 'name' => $zoe->name], 'distance' => 0.2, 'score' => 0.95],
            ['person' => ['id' => $paul->id, 'name' => $paul->name], 'distance' => 0.3, 'score' => 0.9],
            ['person' => ['id' => $lea->id, 'name' => $lea->name], 'distance' => 0.4, 'score' => 0.7],
        ]));

        $data = $this->actingAs($me)->getJson("/vision/faces/{$face->id}/suggest")->assertOk()->json();

        $this->assertSame([$paul->id, $lea->id], array_column(array_column($data['suggestions'], 'person'), 'id'));
        $this->assertTrue($data['suggestions'][0]['on_album']);
        $this->assertFalse($data['suggestions'][1]['on_album']);
        // Paul est déjà dans les suggestions : pas répété dans « sur l'album »
        $this->assertSame([], $data['album_people']);
    }

    public function test_presents_sur_l_album_sans_ressemblance(): void
    {
        $me = User::factory()->create();
        $paul = Person::factory()->create(['name' => 'Paul Cousin']);
        $album = Album::factory()->create(['user_id' => $me->id]);
        $photo = Media::factory()->photo()->create(['user_id' => $me->id]);
        $other = Media::factory()->photo()->create(['user_id' => $me->id]);
        $album->media()->attach([$photo->id, $other->id]);
        $other->people()->attach($paul->id);
        $face = $photo->detectedFaces()->create([
            'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
            'status' => 'unmatched',
            'provider' => 'faceapi',
        ]);
        $this->partialMock(FaceMatcher::class, fn ($m) => $m->shouldReceive('rankedCandidates')->andReturn([]));

        $data = $this->actingAs($me)->getJson("/vision/faces/{$face->id}/suggest")->assertOk()->json();

        $this->assertSame([['id' => $paul->id, 'name' => 'Paul Cousin']], $data['album_people']);
    }
}
