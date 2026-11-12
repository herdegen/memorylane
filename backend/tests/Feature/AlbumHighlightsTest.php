<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Album à thème : ce que la page met en avant avant toutes les photos. */
class AlbumHighlightsTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create();
    }

    private function person(string $first, array $extra = []): Person
    {
        return Person::factory()->create(['user_id' => $this->me->id, 'first_name' => $first, 'name' => "{$first} Test"] + $extra);
    }

    /** Photo de l'album avec ces personnes identifiées et autant de visages. */
    private function photo(Album $album, array $people, ?int $faces = null, ?string $takenAt = '2020-08-15 15:00:00'): Media
    {
        $m = Media::factory()->photo()->create(['user_id' => $this->me->id, 'taken_at' => $takenAt]);
        $album->media()->attach($m->id);
        foreach ($people as $p) {
            $m->people()->attach($p->id);
        }
        foreach (range(1, $faces ?? count($people)) as $i) {
            if (($faces ?? count($people)) === 0) {
                break;
            }
            DetectedFace::create(['media_id' => $m->id, 'bounding_box' => ['x' => $i, 'y' => 1, 'width' => 5, 'height' => 5],
                'confidence' => 0.9, 'provider' => 'insightface', 'status' => 'unmatched']);
        }

        return $m;
    }

    private function sections(Album $album, ?User $as = null): array
    {
        $highlights = $this->actingAs($as ?? $this->me)->getJson("/albums/{$album->id}")->assertOk()->json('highlights');

        return collect($highlights['sections'] ?? [])->keyBy('key')->all();
    }

    public function test_mariage_les_maries_viennent_du_moment_de_vie_puis_temoins_et_groupe(): void
    {
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'mariage']);
        [$matthieu, $marion, $loic, $nicole] = [$this->person('Matthieu'), $this->person('Marion'), $this->person('Loïc'), $this->person('Nicole')];
        PersonRelationship::create(['person1_id' => $matthieu->id, 'person2_id' => $marion->id, 'type' => 'spouse', 'start_date' => '2020-08-15']);
        DB::table('life_events')->insert(['id' => (string) Str::uuid(), 'person_id' => $matthieu->id, 'user_id' => $this->me->id,
            'type' => 'mariage_religieux', 'title' => 'Mariage', 'event_date' => '2020-08-15', 'album_id' => $album->id,
            'created_at' => now(), 'updated_at' => now()]);

        $couple = $this->photo($album, [$matthieu, $marion]);
        $withWitness = $this->photo($album, [$matthieu, $marion, $loic]);
        $crowd = $this->photo($album, [$matthieu, $nicole], faces: 12);
        $this->photo($album, [$nicole, $loic], faces: 9);
        $this->photo($album, [$nicole]);

        $s = $this->sections($album);
        $this->assertSame('Matthieu & Marion', $s['principal']['title']);
        $this->assertNotNull($s['principal']['why']);
        // À deux (3 visages au plus) : la photo avec un témoin en fait partie, pas la foule.
        $this->assertSame([$couple->id, $withWitness->id], $s['principal']['ids']);
        // Pas encore de témoins : bloc vide, avec la proposition (propriétaire)
        $this->assertSame([], $s['witnesses']['people']);
        $this->assertNotNull($s['witnesses']['empty']);
        // Photos de groupe : 8 visages ou plus, la plus grande d'abord
        $this->assertSame($crowd->id, $s['group']['ids'][0]);
        $this->assertCount(2, $s['group']['ids']);

        // On désigne le témoin : ses photos avec les mariés apparaissent.
        $this->actingAs($this->me)->putJson("/albums/{$album->id}/roles", ['role' => 'temoin', 'person_ids' => [$loic->id]])->assertOk();
        $s = $this->sections($album);
        $this->assertSame(['Loïc'], array_column($s['witnesses']['people'], 'first_name'));
        $this->assertSame([$withWitness->id], $s['witnesses']['ids']);
        // Les invités excluent mariés et témoins
        $this->assertNotContains($loic->id, array_column($s['guests']['people'] ?? [], 'id'));
        // Un rôle n'ajoute pas de participant (qui trierait les visages inconnus)
        $this->assertSame(0, DB::table('album_participants')->where('album_id', $album->id)->count());
    }

    public function test_les_maries_choisis_a_la_main_l_emportent(): void
    {
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'mariage']);
        [$christine, $vincent] = [$this->person('Christine'), $this->person('Vincent')];
        $this->photo($album, [$christine, $vincent]);

        $this->assertSame([], $this->sections($album)['principal']['people']);

        $this->actingAs($this->me)->putJson("/albums/{$album->id}/roles", ['role' => 'marie', 'person_ids' => [$christine->id, $vincent->id]])->assertOk();
        $s = $this->sections($album);
        $this->assertSame('Christine & Vincent', $s['principal']['title']);
        $this->assertNull($s['principal']['why']);
    }

    public function test_anniversaire_age_de_la_personne_fetee(): void
    {
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'anniversaire', 'date' => '2018-07-14']);
        $loic = $this->person('Loïc', ['birth_date' => '1988-03-01']);
        $this->photo($album, [$loic], takenAt: '2018-07-14 20:00:00');
        $this->actingAs($this->me)->putJson("/albums/{$album->id}/roles", ['role' => 'fete', 'person_ids' => [$loic->id]])->assertOk();

        $this->assertSame('Loïc · 30 ans', $this->sections($album)['principal']['title']);
    }

    public function test_archives_personnes_decennies_et_photos_sans_date(): void
    {
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'archives']);
        $roger = $this->person('Roger', ['birth_date' => '1928-05-18', 'death_date' => '1981-01-11']);
        $marianne = $this->person('Marianne', ['birth_date' => '1931-01-22']);
        $a = $this->photo($album, [$roger, $marianne], takenAt: '1955-06-01');
        $b = $this->photo($album, [$roger], takenAt: '1972-01-01');
        $c = $this->photo($album, [$marianne], takenAt: '1979-01-01');
        $undated = $this->photo($album, [$marianne], takenAt: null);

        $s = $this->sections($album);
        $people = collect($s['people']['people'])->keyBy('first_name');
        $this->assertSame('1928 – 1981', $people['Roger']['years']);
        $this->assertSame('1931 –', $people['Marianne']['years']);
        $this->assertSame([$a->id, $b->id], $people['Roger']['ids']);
        $this->assertSame([[1950, 'Années 50', [$a->id]], [1970, 'Années 70', [$b->id, $c->id]]],
            array_map(fn ($d) => [$d['key'], $d['title'], $d['ids']], $s['decades']['rows']));
        $this->assertSame([$undated->id], $s['undated']['ids']);
    }

    public function test_voyage_jour_par_jour_avec_le_lieu_et_les_photos_egarees_a_part(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'voyage']);
        $d1 = $this->photo($album, [], takenAt: '2012-08-15 10:00:00');
        $d1b = $this->photo($album, [], takenAt: '2012-08-15 18:00:00');
        $d3 = $this->photo($album, [], takenAt: '2012-08-17 09:00:00');
        $stray = $this->photo($album, [], takenAt: '2018-07-12 12:00:00');
        $undated = $this->photo($album, [], takenAt: null);
        // Berlin déjà connu (cache) pour le jour 1 ; le jour 3 est à résoudre.
        foreach ([[$d1, 52.52, 13.40], [$d1b, 52.52, 13.40], [$d3, 52.39, 13.06]] as [$m, $lat, $lng]) {
            \App\Models\MediaMetadata::create(['media_id' => $m->id, 'latitude' => $lat, 'longitude' => $lng]);
        }
        \Illuminate\Support\Facades\Cache::put('geocode:rev:52.52,13.4', 'Berlin, Allemagne', now()->addDay());

        $s = $this->sections($album)['days'];
        $this->assertSame('3 jours', $s['subtitle']);
        $rows = collect($s['rows'])->keyBy('key');
        $this->assertSame('Jour 1', $rows['2012-08-15']['title']);
        $this->assertSame('mercredi 15 août · Berlin', $rows['2012-08-15']['subtitle']);
        $this->assertSame([$d1->id, $d1b->id], $rows['2012-08-15']['ids']);
        // Un jour sans photo n'a pas de ligne, mais la numérotation suit le calendrier.
        $this->assertSame('Jour 3', $rows['2012-08-17']['title']);
        $this->assertSame('vendredi 17 août', $rows['2012-08-17']['subtitle']);
        $this->assertSame([$stray->id], $rows['others']['ids']);
        $this->assertSame([$undated->id], $rows['undated']['ids']);
        // Le lieu inconnu est cherché en arrière-plan.
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ResolvePlaceNames::class,
            fn ($job) => $job->points === [[52.39, 13.06]]);
    }

    public function test_voyage_de_moins_de_trois_semaines_jamais_coupe_par_des_jours_sans_photo(): void
    {
        // Ski du 7 au 17 avril, rien les 14 et 15 : un seul séjour, rien dans « Autres dates ».
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'voyage']);
        foreach (['2009-04-07', '2009-04-13', '2009-04-16', '2009-04-17'] as $d) {
            $this->photo($album, [], takenAt: "{$d} 11:00:00");
        }

        $rows = collect($this->sections($album)['days']['rows']);
        $this->assertSame(['Jour 1', 'Jour 7', 'Jour 10', 'Jour 11'], $rows->pluck('title')->all());
        $this->assertNull($rows->firstWhere('key', 'others'));
    }

    public function test_voyage_d_une_seule_journee_rien_de_plus(): void
    {
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'voyage']);
        $this->photo($album, [], takenAt: '2000-07-15 10:00:00');
        $this->photo($album, [], takenAt: '2000-07-15 16:00:00');

        $this->actingAs($this->me)->getJson("/albums/{$album->id}")->assertOk()->assertJsonPath('highlights', null);
    }

    public function test_sans_theme_ou_voyage_rien_de_plus(): void
    {
        foreach ([null, 'voyage', 'moment'] as $theme) {
            $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => $theme]);
            $this->photo($album, [$this->person('Paul')]);
            $this->actingAs($this->me)->getJson("/albums/{$album->id}")->assertOk()->assertJsonPath('highlights', null);
        }
    }

    public function test_roles_reserves_au_proprietaire_et_liste_fermee(): void
    {
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'mariage']);
        $paul = $this->person('Paul');
        $other = User::factory()->create();

        $this->actingAs($other)->putJson("/albums/{$album->id}/roles", ['role' => 'temoin', 'person_ids' => [$paul->id]])->assertForbidden();
        $this->actingAs($this->me)->putJson("/albums/{$album->id}/roles", ['role' => 'chauffeur', 'person_ids' => [$paul->id]])->assertUnprocessable();
        $this->assertSame(0, DB::table('album_roles')->count());

        // Retirer tout le monde d'un rôle
        $this->actingAs($this->me)->putJson("/albums/{$album->id}/roles", ['role' => 'temoin', 'person_ids' => [$paul->id]])->assertOk();
        $this->actingAs($this->me)->putJson("/albums/{$album->id}/roles", ['role' => 'temoin', 'person_ids' => []])->assertOk();
        $this->assertSame(0, DB::table('album_roles')->count());
    }

    public function test_un_lecteur_ne_voit_pas_les_blocs_vides_a_remplir(): void
    {
        $album = Album::factory()->create(['user_id' => $this->me->id, 'theme' => 'mariage']);
        $this->photo($album, [$this->person('Paul'), $this->person('Jeanne')]);
        // Un membre du cercle avec lequel l'album est partagé : il lit, il ne modifie pas.
        $reader = User::factory()->create();
        $circle = \App\Models\Household::factory()->create(['created_by' => $this->me->id]);
        $circle->members()->attach([$this->me->id, $reader->id]);
        $album->households()->attach($circle->id, ['granted_by' => $this->me->id]);

        $s = $this->sections($album, $reader);
        $this->assertArrayNotHasKey('witnesses', $s);
        $this->assertArrayNotHasKey('principal', $s);
    }
}
