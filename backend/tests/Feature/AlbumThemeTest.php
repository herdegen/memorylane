<?php

namespace Tests\Feature;

use App\Console\Commands\SuggestAlbumThemes;
use App\Models\Album;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Thème d'album : choix à la création / modification, propositions automatiques. */
class AlbumThemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_theme_choisi_a_la_creation_et_modifiable(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->post('/albums', ['name' => 'Bretagne 2000', 'theme' => 'voyage'])->assertRedirect();
        $album = Album::where('name', 'Bretagne 2000')->firstOrFail();
        $this->assertSame('voyage', $album->theme);

        $this->actingAs($me)->put("/albums/{$album->id}", ['name' => 'Bretagne 2000', 'theme' => 'fete'])->assertRedirect();
        $this->assertSame('fete', $album->fresh()->theme);

        // Sans thème : retour à l'affichage par défaut.
        $this->actingAs($me)->put("/albums/{$album->id}", ['name' => 'Bretagne 2000', 'theme' => null])->assertRedirect();
        $this->assertNull($album->fresh()->theme);
    }

    public function test_theme_hors_liste_refuse(): void
    {
        $me = User::factory()->create();

        $this->actingAs($me)->post('/albums', ['name' => 'X', 'theme' => 'pique-nique'])
            ->assertSessionHasErrors('theme');
        $this->assertDatabaseMissing('albums', ['name' => 'X']);
    }

    public function test_propositions_evidentes_seulement(): void
    {
        $cases = [
            'Mariage Christine Vincent 1984' => 'mariage',
            'Anniversaire Latino 40 ans mariage, 20 ans Matthieu' => 'anniversaire',
            'Cousinade / Dépendaison Gournay Juin 2025' => 'reunion',
            'Cousinades 2011' => 'reunion',
            'Premiere communion Matthieu' => 'bapteme',
            'Noël 1996' => 'fete',
            'Nouvel an 2017' => 'fete',
            'Halloween 2017' => 'fete',
            'Soirée Normandie Juillet 2012' => 'fete',
            'Archives de Marianne Kormann' => 'archives',
            'Vacances Bretagne' => 'voyage',
            // Pas évident : on ne devine pas
            'Berlin 2012' => null,
            'Astérix est là' => null,
            'Année P11' => null,
            // « fete » dans un autre mot ne compte pas
            'Fêtards de Lyon' => null,
        ];
        foreach ($cases as $name => $expected) {
            $this->assertSame($expected, SuggestAlbumThemes::suggest($name)[0], $name);
        }
        // Le moment de vie lié passe avant le nom.
        $this->assertSame('mariage', SuggestAlbumThemes::suggest('Été 2020', 'mariage_religieux')[0]);
    }

    public function test_commande_n_ecrit_qu_avec_apply_et_garde_les_themes_choisis(): void
    {
        $me = User::factory()->create();
        $wedding = Album::factory()->create(['user_id' => $me->id, 'name' => 'Été 2020']);
        $noel = Album::factory()->create(['user_id' => $me->id, 'name' => 'Noël 1996']);
        $chosen = Album::factory()->create(['user_id' => $me->id, 'name' => 'Mariage de Paul', 'theme' => 'voyage']);
        $berlin = Album::factory()->create(['user_id' => $me->id, 'name' => 'Berlin 2012']);
        $person = Person::factory()->create();
        DB::table('life_events')->insert([
            'id' => (string) Str::uuid(), 'person_id' => $person->id, 'user_id' => $me->id,
            'type' => 'mariage_religieux', 'title' => 'Mariage', 'event_date' => '2020-08-15', 'album_id' => $wedding->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->artisan('albums:suggest-themes')->assertSuccessful();
        $this->assertNull($noel->fresh()->theme);

        $this->artisan('albums:suggest-themes', ['--apply' => true])->assertSuccessful();
        $this->assertSame('mariage', $wedding->fresh()->theme);
        $this->assertSame('fete', $noel->fresh()->theme);
        $this->assertSame('voyage', $chosen->fresh()->theme);
        $this->assertNull($berlin->fresh()->theme);
    }
}
