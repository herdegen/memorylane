<?php

namespace Tests\Feature;

use App\Jobs\GeocodePendingPlaces;
use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\FamilyTimelineService;
use App\Services\GeocodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * « La famille dans le temps » sur la carte : trajectoires anonymes
 * (naissance → lieux de vie → décès / adresse actuelle), déplacement à la
 * médiane entre deux étapes, portée « sa famille » comme la couche « où vit
 * la famille », et géocodage des lieux en tâche de fond.
 */
class FamilyTimelineMapTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;

    private Person $me;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-01-01 00:00:00');
        config(['memorylane.nominatim_interval' => 0]);
        Http::preventStrayRequests();

        $this->viewer = User::factory()->create();
        $this->me = Person::factory()->create(['user_id' => $this->viewer->id, 'name' => 'Moi Même', 'birth_date' => null]);
        $this->viewer->update(['person_id' => $this->me->id]);

        $this->place('Paris', 48.8566, 2.3522);
        $this->place('Marseille', 43.2965, 5.3698);
        $this->place('Lyon', 45.7640, 4.8357);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Lieu déjà géocodé (comme après un passage de people:geocode-places). */
    private function place(string $name, float $lat, float $lng): void
    {
        Cache::put(GeocodeService::placeKey($name), ['latitude' => $lat, 'longitude' => $lng], now()->addDay());
    }

    /** Un parent de « moi » (donc dans sa famille). */
    private function parent(array $attrs = []): Person
    {
        $parent = Person::factory()->create(['user_id' => $this->viewer->id, 'birth_date' => null, ...$attrs]);
        $this->me->forceFill(['father_id' => $parent->id])->saveQuietly();

        return $parent;
    }

    private function timeline(?User $user = null): array
    {
        return $this->actingAs(($user ?? $this->viewer)->fresh())->getJson('/map/heatmap/timeline')->assertOk()->json();
    }

    /** Segments arrondis pour comparer sans se soucier des flottants. */
    private function segments(array $track): array
    {
        return array_map(fn ($s) => [round($s['from'], 1), round($s['to'], 1), round($s['lat'], 2), round($s['lng'], 2)], $track);
    }

    // ------------------------------------------------------------ médiane

    public function test_entre_naissance_et_deces_on_change_de_lieu_a_la_mediane(): void
    {
        $this->parent([
            'birth_date' => '1900-01-01', 'birth_place' => 'Paris',
            'death_date' => '1980-01-01', 'death_place' => 'Marseille',
        ]);

        $data = $this->timeline();

        $this->assertCount(1, $data['tracks']);
        // À Paris jusqu'en 1940 (milieu de 1900-1980), puis à Marseille.
        $this->assertSame([
            [1900.0, 1940.0, 48.86, 2.35],
            [1940.0, 1980.0, 43.3, 5.37],
        ], $this->segments($data['tracks'][0]));
        $this->assertEquals(['from' => 1900, 'to' => 1980], $data['range']);
    }

    public function test_sans_lieu_de_naissance_on_le_suppose_au_lieu_de_deces(): void
    {
        $this->parent([
            'birth_date' => '1900-01-01', 'birth_place' => null,
            'death_date' => '1980-01-01', 'death_place' => 'Marseille',
        ]);

        // Présent de sa naissance à son décès, à Marseille.
        $this->assertSame([[1900.0, 1980.0, 43.3, 5.37]], $this->segments($this->timeline()['tracks'][0]));
    }

    public function test_variantes_des_lieux_mal_saisis(): void
    {
        $this->assertContains('Paris, France', \App\Services\GeocodeService::placeVariants('Paris-Xème, 75110, Paris, Île-de-France, France'));
        $this->assertContains('Paris, France', \App\Services\GeocodeService::placeVariants('Paris 10e arrondissement, France'));
        $this->assertContains('Metz, France', \App\Services\GeocodeService::placeVariants('Metz, 57463, Moselle, Lorraine, France à l\'âge de 74 ans'));
        $this->assertContains('Montrenge, France', \App\Services\GeocodeService::placeVariants('Montrenge (Seinx), France'));
    }

    public function test_un_lieu_de_vie_intermediaire_decoupe_aux_medianes(): void
    {
        $father = $this->parent([
            'birth_date' => '1900-01-01', 'birth_place' => 'Paris',
            'death_date' => '1980-01-01', 'death_place' => 'Marseille',
        ]);
        // Lyon de 1920 à 1930 (coordonnées du moment), Bordeaux en 1960
        // (lieu en texte, géocodé).
        $this->place('Bordeaux', 44.8378, -0.5792);
        LifeEvent::create(['person_id' => $father->id, 'user_id' => $this->viewer->id, 'type' => 'residence', 'title' => 'Lyon',
            'place' => 'Lyon (Croix-Rousse)', 'latitude' => 45.774, 'longitude' => 4.832, 'event_date' => '1920-01-01', 'end_date' => '1930-01-01']);
        LifeEvent::create(['person_id' => $father->id, 'user_id' => $this->viewer->id, 'type' => 'residence', 'title' => 'Bordeaux',
            'place' => 'Bordeaux', 'event_date' => '1960-01-01']);
        // Les autres moments ne déplacent personne.
        LifeEvent::create(['person_id' => $father->id, 'user_id' => $this->viewer->id, 'type' => 'job', 'title' => 'Usine',
            'place' => 'Lyon', 'event_date' => '1935-01-01']);

        $track = $this->timeline()['tracks'][0];

        $this->assertSame([
            [1900.0, 1910.0, 48.86, 2.35],   // Paris → milieu de 1900-1920
            [1910.0, 1945.0, 45.77, 4.83],   // Lyon, encore là en 1930 → milieu de 1930-1960
            [1945.0, 1970.0, 44.84, -0.58],  // Bordeaux → milieu de 1960-1980
            [1970.0, 1980.0, 43.3, 5.37],    // Marseille jusqu'au décès
        ], $this->segments($track));
    }

    // ------------------------------------------------------------ présence

    public function test_personne_vivante_jusqu_a_aujourd_hui_a_son_adresse_arrondie(): void
    {
        $father = $this->parent(['birth_date' => '1990-01-01', 'birth_place' => 'Paris']);
        DB::table('people')->where('id', $father->id)->update(['address_latitude' => 48.866421, 'address_longitude' => 2.441441]);

        $data = $this->timeline();

        // Paris jusqu'au milieu de 1990-2026, puis l'adresse actuelle à ~1 km.
        $this->assertSame([
            [1990.0, 2008.0, 48.86, 2.35],
            [2008.0, 2026.0, 48.87, 2.44],
        ], $this->segments($data['tracks'][0]));
        $this->assertEquals(2026, $data['range']['to']);
        $this->assertStringNotContainsString('48.866421', json_encode($data));
    }

    public function test_sans_date_de_deces_presence_bornee(): void
    {
        // Née en 1880, sans date de décès : plus vivante ; ses enfants (plus
        // bas) la situent à Marseille en 1930 et à Paris en 1950, elle reste
        // présente jusqu'à sa dernière étape + 10 ans.
        $this->parent(['birth_date' => '1880-01-01', 'birth_place' => 'Lyon']);
        // Né en 1950, pas de décès : vivant, présent jusqu'à aujourd'hui (sans adresse).
        $uncle = Person::factory()->create(['user_id' => $this->viewer->id, 'birth_date' => '1950-01-01', 'birth_place' => 'Paris', 'father_id' => $this->me->father_id]);
        // Décédé (lieu connu, date inconnue) : ni adresse actuelle ni « aujourd'hui ».
        $gone = Person::factory()->create(['user_id' => $this->viewer->id, 'birth_date' => '1930-01-01', 'birth_place' => 'Marseille',
            'death_place' => 'Marseille', 'father_id' => $this->me->father_id]);
        DB::table('people')->where('id', $gone->id)->update(['address_latitude' => 45.0, 'address_longitude' => 1.0]);

        $tracks = collect($this->timeline()['tracks'])->map(fn ($t) => $this->segments($t))->sortBy(fn ($t) => $t[0][0])->values()->all();

        $this->assertSame([
            [[1880.0, 1905.0, 45.76, 4.84], [1905.0, 1940.0, 43.3, 5.37], [1940.0, 1960.0, 48.86, 2.35]],
            [[1930.0, 2000.0, 43.3, 5.37]],
            [[1950.0, 2026.0, 48.86, 2.35]],
        ], $tracks);
        $this->assertNotNull($uncle);
    }

    // ------------------------------------------------------------ lieux

    public function test_les_lieux_non_resolus_sont_ignores_sans_appel_reseau(): void
    {
        Http::fake();
        // Naissance dans un lieu jamais géocodé, décès à Paris : seul Paris compte.
        $this->parent([
            'birth_date' => '1950-01-01', 'birth_place' => 'Atlantide',
            'death_date' => '2000-01-01', 'death_place' => 'Paris',
        ]);
        // Seul lieu introuvable (raté mémorisé) : la personne est ignorée.
        Cache::put(GeocodeService::placeKey('Ys, Bretagne'), 'miss', now()->addDay());
        Person::factory()->create(['user_id' => $this->viewer->id, 'birth_date' => '1920-01-01', 'birth_place' => 'Ys, Bretagne', 'father_id' => $this->me->father_id]);

        $data = $this->timeline();

        $this->assertCount(1, $data['tracks']);
        $this->assertSame([[1950.0, 2000.0, 48.86, 2.35]], $this->segments($data['tracks'][0]));
        // « Atlantide » reste à géocoder (pas le raté connu) ; aucun Nominatim pendant la requête.
        $this->assertSame(1, $data['pending_places']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------ portée

    public function test_seulement_sa_famille_et_rien_sans_fiche_moi_ni_en_mode_amis(): void
    {
        $family = ['birth_date' => '1900-01-01', 'birth_place' => 'Paris', 'death_date' => '1980-01-01', 'death_place' => 'Marseille'];
        $this->parent($family);
        // Hors famille (aucun lien) et fiche familiale hors répertoire : absents.
        Person::factory()->create(['user_id' => $this->viewer->id, ...$family]);
        Person::factory()->create(['user_id' => $this->viewer->id, 'in_family_directory' => false, 'father_id' => $this->me->father_id, ...$family]);

        $this->assertCount(1, $this->timeline()['tracks']);

        // Sans fiche « moi » : pas de famille définie.
        $this->assertSame(['tracks' => [], 'range' => null, 'pending_places' => 0], $this->timeline(User::factory()->create()));

        // Cercle d'amis sélectionné : rien.
        $band = Household::factory()->friends()->create(['created_by' => $this->viewer->id]);
        $band->members()->attach($this->viewer->id);
        $this->viewer->forceFill(['current_household_id' => $band->id])->save();
        $this->assertSame([], $this->timeline()['tracks']);
    }

    public function test_anonyme_ni_nom_ni_identifiant_ni_lieu(): void
    {
        $father = $this->parent([
            'name' => 'Gaston Lagaffe', 'first_name' => 'Gaston', 'last_name' => 'Lagaffe',
            'birth_date' => '1900-01-01', 'birth_place' => 'Paris',
            'death_date' => '1980-01-01', 'death_place' => 'Marseille',
        ]);
        LifeEvent::create(['person_id' => $father->id, 'user_id' => $this->viewer->id, 'type' => 'residence', 'title' => 'Rue des Bourdonnais',
            'place' => 'Lyon', 'event_date' => '1950-01-01']);

        $response = $this->actingAs($this->viewer)->getJson('/map/heatmap/timeline')->assertOk();
        $content = $response->getContent();

        foreach (['Gaston', 'Lagaffe', 'Paris', 'Marseille', 'Lyon', 'Bourdonnais', $father->id, $this->me->id, $this->viewer->id, $father->slug] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $content);
        }
        foreach ($response->json('tracks.0') as $segment) {
            $this->assertSame(['from', 'to', 'lat', 'lng'], array_keys($segment));
        }
    }

    // ------------------------------------------------------------ unions, enfants

    public function test_union_et_naissance_d_un_enfant_sont_des_lieux_de_vie(): void
    {
        $this->place('Bordeaux', 44.8378, -0.5792);
        $father = $this->parent([
            'birth_date' => '1900-01-01', 'birth_place' => 'Paris',
            'death_date' => '1980-01-01', 'death_place' => 'Marseille',
        ]);
        $mother = Person::factory()->create(['user_id' => $this->viewer->id, 'birth_date' => '1902-01-01', 'death_date' => '1990-01-01']);
        // Mariés à Lyon en 1925 (les deux conjoints y vivaient)…
        PersonRelationship::create(['person1_id' => $father->id, 'person2_id' => $mother->id, 'type' => 'spouse',
            'start_date' => '1925-01-01', 'start_place' => 'Lyon']);
        // … un enfant né à Bordeaux en 1935 (ses deux parents y vivaient).
        Person::factory()->create(['user_id' => $this->viewer->id, 'father_id' => $father->id, 'mother_id' => $mother->id,
            'birth_date' => '1935-01-01', 'birth_place' => 'Bordeaux']);

        $tracks = collect($this->timeline()['tracks'])->map(fn ($t) => $this->segments($t))->sortBy(fn ($t) => $t[0][0])->values()->all();

        $this->assertSame([
            // Le père : Paris → Lyon (milieu de 1900-1925) → Bordeaux (milieu
            // de 1925-1935) → Marseille (milieu de 1935-1980).
            [[1900.0, 1912.5, 48.86, 2.35], [1912.5, 1930.0, 45.76, 4.84], [1930.0, 1957.5, 44.84, -0.58], [1957.5, 1980.0, 43.3, 5.37]],
            // La mère, sans autre lieu connu : Lyon, puis Bordeaux jusqu'à son décès.
            [[1902.0, 1930.0, 45.76, 4.84], [1930.0, 1990.0, 44.84, -0.58]],
            // L'enfant, vivant.
            [[1935.0, 2026.0, 44.84, -0.58]],
        ], $tracks);
    }

    // ------------------------------------------------------------ sorties photo

    /** Photo du compte, prise là où la fiche est identifiée. */
    private function photo(Person $person, string $takenAt, float $lat, float $lng, ?string $ficheStatus = null): Media
    {
        $media = Media::factory()->create(['user_id' => $this->viewer->id, 'type' => 'photo', 'taken_at' => $takenAt]);
        $media->metadata()->create(['latitude' => $lat, 'longitude' => $lng]);
        $media->people()->attach($person->id, ['fiche_status' => $ficheStatus]);

        return $media;
    }

    public function test_les_photos_de_voyage_sont_des_sorties_sans_effet_sur_les_medianes(): void
    {
        $father = $this->parent([
            'birth_date' => '1950-01-01', 'birth_place' => 'Paris',
            'death_date' => '2020-01-01', 'death_place' => 'Marseille',
        ]);
        // Juin 2012 : deux photos à Rome, une à Florence → une seule sortie, à Rome.
        $this->photo($father, '2012-06-10 12:00:00', 41.9028, 12.4964);
        $this->photo($father, '2012-06-12 18:00:00', 41.8986, 12.4769);
        $this->photo($father, '2012-06-20 10:00:00', 43.7696, 11.2558);
        // Mars 2015, à 20 km de chez lui (Aix-en-Provence) : pas une sortie.
        $this->photo($father, '2015-03-01 10:00:00', 43.5297, 5.4474);
        // Août 2018 à Berlin, mais identification refusée sur la fiche : ignorée.
        $this->photo($father, '2018-08-01 10:00:00', 52.52, 13.405, 'private');
        // 1985 : avant la frise au mois, ignorée.
        $this->photo($father, '1985-07-01 10:00:00', 52.52, 13.405);

        $track = $this->timeline()['tracks'][0];

        $june = FamilyTimelineService::decimalYear(Carbon::parse('2012-06-01'));
        $july = FamilyTimelineService::decimalYear(Carbon::parse('2012-07-01'));
        // Médiane naissance-décès inchangée (1985), la sortie est posée par-dessus.
        $this->assertSame([
            [1950.0, 1985.0, 48.86, 2.35],
            [1985.0, round($june, 1), 43.3, 5.37],
            [round($june, 1), round($july, 1), 41.9, 12.49],
            [round($july, 1), 2020.0, 43.3, 5.37],
        ], $this->segments($track));
        $this->assertEqualsWithDelta($june, $track[2]['from'], 0.001);
        $this->assertEqualsWithDelta($july, $track[2]['to'], 0.001);
        $this->assertTrue($track[2]['trip']);
        $this->assertCount(1, array_filter($track, fn ($s) => ! empty($s['trip'])));
    }

    public function test_sorties_photo_reglables_et_limitees_aux_photos_visibles(): void
    {
        $father = $this->parent(['birth_date' => '1950-01-01', 'birth_place' => 'Paris', 'death_date' => '2020-01-01', 'death_place' => 'Paris']);
        $this->photo($father, '2012-06-10 12:00:00', 41.9028, 12.4964);
        // Photo d'un autre compte, jamais partagée : invisible pour moi, donc ignorée.
        $hidden = Media::factory()->create(['type' => 'photo', 'taken_at' => '2016-05-02 12:00:00']);
        $hidden->metadata()->create(['latitude' => 40.4168, 'longitude' => -3.7038]);
        $hidden->people()->attach($father->id);

        $trips = fn () => count(array_filter($this->timeline()['tracks'][0], fn ($s) => ! empty($s['trip'])));
        config(['memorylane.timeline.trip_min_photos' => 1]);
        $this->assertSame(1, $trips());

        // Deux photos minimum dans le mois (réglage par défaut) : la photo
        // seule de Rome ne suffit plus.
        config(['memorylane.timeline.trip_min_photos' => 2]);
        $this->assertSame(0, $trips());
        // Seuil de distance au-delà de Paris-Rome : plus de sortie non plus.
        config(['memorylane.timeline.trip_min_photos' => 1, 'memorylane.timeline.trip_min_km' => 2000]);
        $this->assertSame(0, $trips());
    }

    // ------------------------------------------------------------ géocodage automatique

    public function test_un_lieu_saisi_ou_modifie_part_au_geocodage(): void
    {
        config(['memorylane.geocode_auto' => true]);
        Queue::fake();

        // Fiche : lieu de naissance saisi (Paris est déjà connu, pas Mannheim).
        $person = Person::factory()->create(['user_id' => $this->viewer->id, 'birth_place' => 'Mannheim, Allemagne', 'death_place' => 'Paris']);
        Queue::assertPushed(GeocodePendingPlaces::class);
        $this->assertSame(['Mannheim, Allemagne'], array_values(GeocodePendingPlaces::pending()));

        // Lieu de décès modifié ; un autre champ modifié ne déclenche rien.
        $person->update(['death_place' => 'Mechelen, Belgique']);
        $person->update(['notes' => 'Rien de géographique']);
        // Union, lieu de vie sans coordonnées ; pas un lieu de vie déjà situé.
        PersonRelationship::create(['person1_id' => $person->id, 'person2_id' => $this->me->id, 'type' => 'spouse', 'start_place' => 'Strasbourg, , ,']);
        LifeEvent::create(['person_id' => $person->id, 'user_id' => $this->viewer->id, 'type' => 'residence', 'title' => 'Ys',
            'place' => 'Quimper', 'event_date' => '1950-01-01']);
        LifeEvent::create(['person_id' => $person->id, 'user_id' => $this->viewer->id, 'type' => 'residence', 'title' => 'Brest',
            'place' => 'Brest', 'latitude' => 48.39, 'longitude' => -4.49, 'event_date' => '1960-01-01']);

        $this->assertSame(['Mannheim, Allemagne', 'Mechelen, Belgique', 'Strasbourg', 'Quimper'], array_values(GeocodePendingPlaces::pending()));
    }

    public function test_le_job_geocode_au_rythme_de_nominatim_sans_reessayer_un_introuvable(): void
    {
        config(['memorylane.geocode_auto' => true]);
        $this->fakeNominatim(['Mannheim, Allemagne' => [49.4875, 8.466]]);
        Queue::fake();
        GeocodePendingPlaces::enqueue(['Mannheim, Allemagne', 'Atlantide', 'Paris', '  ']);
        $this->assertSame(['Mannheim, Allemagne', 'Atlantide'], array_values(GeocodePendingPlaces::pending()));

        (new GeocodePendingPlaces)->handle(app(GeocodeService::class));

        $this->assertSame([], GeocodePendingPlaces::pending());
        $this->assertEqualsWithDelta(49.4875, Cache::get(GeocodeService::placeKey('Mannheim, Allemagne'))['latitude'], 0.0001);
        $this->assertSame('miss', Cache::get(GeocodeService::placeKey('Atlantide')));
        Http::assertSentCount(2);

        // Introuvable mémorisé : une nouvelle saisie ne le remet pas en liste.
        GeocodePendingPlaces::enqueue(['Atlantide']);
        $this->assertSame([], GeocodePendingPlaces::pending());
    }

    public function test_une_requete_par_seconde_au_plus_entre_tous_les_appels(): void
    {
        config(['memorylane.nominatim_interval' => 0.4]);
        $this->fakeNominatim([]);
        $geocoder = app(GeocodeService::class);

        $start = microtime(true);
        $geocoder->lookup('Atlantide');
        $geocoder->lookup('Lemurie');
        $geocoder->lookup('Hyperborée');

        // Trois appels : au moins deux intervalles entre eux.
        Http::assertSentCount(3);
        $this->assertGreaterThanOrEqual(0.8, microtime(true) - $start);
    }

    public function test_la_carte_met_en_attente_les_lieux_jamais_tentes(): void
    {
        $this->parent(['birth_date' => '1950-01-01', 'birth_place' => 'Atlantide', 'death_date' => '2000-01-01', 'death_place' => 'Paris']);
        config(['memorylane.geocode_auto' => true]);
        Queue::fake();

        $this->assertSame(1, $this->timeline()['pending_places']);

        Queue::assertPushed(GeocodePendingPlaces::class);
        $this->assertSame(['Atlantide'], array_values(GeocodePendingPlaces::pending()));
        Http::assertNothingSent();
    }

    public function test_exige_l_authentification(): void
    {
        $this->getJson('/map/heatmap/timeline')->assertUnauthorized();
    }

    // ------------------------------------------------------------ géocodage

    /** Nominatim simulé : [requête => [lat, lon]] ; ailleurs, introuvable. */
    private function fakeNominatim(array $answers): void
    {
        Http::fake(function (Request $request) use ($answers) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $hit = $answers[$query['q'] ?? ''] ?? null;

            return Http::response($hit ? [['lat' => (string) $hit[0], 'lon' => (string) $hit[1]]] : []);
        });
    }

    private function sentQueries(): array
    {
        return Http::recorded()->map(function ($pair) {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return $query['q'] ?? null;
        })->all();
    }

    public function test_la_commande_geocode_les_lieux_manquants_et_reutilise_le_cache(): void
    {
        $this->fakeNominatim(['Mannheim, Allemagne' => [49.4875, 8.466], 'Colmar' => [48.08, 7.36]]);
        $father = $this->parent(['birth_place' => 'Mannheim, Allemagne', 'death_place' => 'Paris']);
        // Lieu d'une union.
        PersonRelationship::create(['person1_id' => $father->id, 'person2_id' => Person::factory()->create(['user_id' => $this->viewer->id])->id,
            'type' => 'spouse', 'start_place' => 'Colmar']);
        // Lieu de vie sans coordonnées, lieu GEDCOM à virgules vides.
        LifeEvent::create(['person_id' => $this->me->father_id, 'user_id' => $this->viewer->id, 'type' => 'residence', 'title' => 'Lyon',
            'place' => 'Lyon, , ,', 'event_date' => '1950-01-01']);
        // Hors répertoire familial : ignoré.
        Person::factory()->create(['user_id' => $this->viewer->id, 'in_family_directory' => false, 'birth_place' => 'Tokyo']);

        $this->artisan('people:geocode-places')->assertSuccessful();

        // Paris et Lyon étaient en cache : un appel pour Mannheim, un pour Colmar.
        $queries = $this->sentQueries();
        sort($queries);
        $this->assertSame(['Colmar', 'Mannheim, Allemagne'], $queries);
        $this->assertEqualsWithDelta(49.4875, Cache::get(GeocodeService::placeKey('Mannheim, Allemagne'))['latitude'], 0.0001);

        // Second passage : tout est connu, aucun appel.
        $this->artisan('people:geocode-places')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_un_lieu_introuvable_n_est_pas_retente_a_chaque_passage(): void
    {
        $this->fakeNominatim([]);
        $this->parent(['birth_place' => 'Atlantide']);

        $this->artisan('people:geocode-places')->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame('miss', Cache::get(GeocodeService::placeKey('Atlantide')));

        $this->artisan('people:geocode-places')->assertSuccessful();
        Http::assertSentCount(1);

        // Sauf si on le demande.
        $this->artisan('people:geocode-places --retry-misses')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_un_lieu_detaille_est_retente_en_ville_et_pays(): void
    {
        $this->fakeNominatim(['Gournay-sur-Marne, France' => [48.8606, 2.5747], 'Paris, France' => [48.8566, 2.3522]]);
        $this->parent(['birth_place' => 'Gournay-sur-Marne, 93033, Seine-Saint-Denis, Ile-de-France, France']);
        Person::factory()->create(['user_id' => $this->viewer->id, 'birth_place' => 'Paris 10e arrondissement, France']);

        $this->artisan('people:geocode-places')->assertSuccessful();

        // Pour chaque lieu (dans un ordre quelconque), les formes essayées dans l'ordre.
        $tried = fn (string $prefix) => array_values(array_filter($this->sentQueries(), fn ($q) => str_starts_with($q, $prefix)));
        $this->assertSame([
            'Gournay-sur-Marne, 93033, Seine-Saint-Denis, Ile-de-France, France',
            'Gournay-sur-Marne, Seine-Saint-Denis, Ile-de-France, France',
            'Gournay-sur-Marne, France',
        ], $tried('Gournay'));
        $this->assertSame(['Paris 10e arrondissement, France', 'Paris, France'], $tried('Paris'));
        Http::assertSentCount(5);
        // Le lieu tel que saisi est désormais résolu (la carte lit cette clé).
        $this->assertEqualsWithDelta(2.5747, Cache::get(GeocodeService::placeKey('Gournay-sur-Marne, 93033, Seine-Saint-Denis, Ile-de-France, France'))['longitude'], 0.0001);
        $this->assertEqualsWithDelta(48.8566, Cache::get(GeocodeService::placeKey('Paris 10e arrondissement, France'))['latitude'], 0.0001);
    }

    public function test_dry_run_n_appelle_rien_et_liste_les_lieux(): void
    {
        Http::fake();
        $this->parent(['birth_place' => 'Mechelen, Antwerpen, Belgique', 'death_place' => 'Paris']);

        $this->artisan('people:geocode-places --dry-run')
            ->expectsOutputToContain('1 à résoudre')
            ->expectsOutputToContain('Mechelen, Antwerpen, Belgique')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertFalse(Cache::has(GeocodeService::placeKey('Mechelen, Antwerpen, Belgique')));
    }

    public function test_nominatim_en_panne_arret_sans_noter_introuvable_longtemps(): void
    {
        $down = true;
        Http::fake(function () use (&$down) {
            return $down ? Http::response('', 503) : Http::response([]);
        });
        foreach (['Aa', 'Bbb', 'Ccc', 'Ddd', 'Eee'] as $place) {
            Person::factory()->create(['user_id' => $this->viewer->id, 'birth_place' => "{$place}ville"]);
        }

        $this->artisan('people:geocode-places')->assertFailed();

        // Trois pannes de suite : on s'arrête là.
        Http::assertSentCount(3);

        // Panne ≠ introuvable : les trois sont retentés deux heures plus tard
        // (un vrai « introuvable » attendrait une semaine), avec les deux autres.
        $down = false;
        $this->travel(2)->hours();
        $this->artisan('people:geocode-places')->assertSuccessful();
        Http::assertSentCount(8);
    }
}
