<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fenêtre « Ajouter des médias » d'un album : options de filtres, filtre
 * « pas encore dans un album », recherche dans la description ; et un album
 * vide se supprime.
 */
class AlbumPickerFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_options_et_filtre_pas_encore_dans_un_album(): void
    {
        $user = User::factory()->create();
        $album = Album::factory()->create(['user_id' => $user->id]);
        $rangee = Media::factory()->photo()->create(['user_id' => $user->id, 'taken_at' => '1988-06-16']);
        $album->media()->attach($rangee->id);
        $libre = Media::factory()->photo()->create(['user_id' => $user->id, 'taken_at' => '1994-05-04', 'description' => 'Baptême de Julien']);

        $this->actingAs($user)->getJson('/media/filter-options')->assertOk()
            ->assertJsonPath('years', [1994, 1988])
            ->assertJsonPath('counts.no_album', 1);

        $ids = fn (string $q) => collect($this->actingAs($user)->getJson('/media?'.$q)->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$libre->id], $ids('no_album=1'));
        $this->assertSame([$libre->id], $ids('search=bapteme'));
    }

    public function test_un_album_vide_se_supprime(): void
    {
        $user = User::factory()->create();
        $album = Album::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user)->deleteJson("/albums/{$album->id}")->assertOk();
        $this->assertSoftDeleted($album);
    }
}
