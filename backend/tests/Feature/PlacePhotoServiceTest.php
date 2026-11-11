<?php

namespace Tests\Feature;

use App\Services\PlacePhotoService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Photo d'un lieu : image principale Wikidata de la localité, puis de la
 * commune qui contient le point (Nominatim), puis article Wikipédia, puis
 * photo Commons voisine qui mentionne le lieu.
 */
class PlacePhotoServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['memorylane.nominatim_interval' => 0]);
    }

    /**
     * Faux services : chaque clé est un morceau d'URL (décodée), la valeur
     * la réponse ; tout le reste répond vide.
     *
     * @param  array<string, mixed>  $routes
     */
    private function fakeServices(array $routes): void
    {
        Http::fake(function (Request $request) use ($routes) {
            $url = urldecode($request->url());
            foreach ($routes as $needle => $response) {
                if (str_contains($url, $needle)) {
                    return is_callable($response) ? $response() : $response;
                }
            }

            return Http::response([]);
        });
    }

    /** Entité Wikidata minimale : coordonnées (P625) et image principale (P18). */
    private function entity(?array $coords, ?string $image): array
    {
        $claims = [];
        if ($coords) {
            $claims['P625'] = [['rank' => 'normal', 'mainsnak' => ['datavalue' => ['value' => ['latitude' => $coords[0], 'longitude' => $coords[1]]]]]];
        }
        if ($image) {
            $claims['P18'] = [['rank' => 'normal', 'mainsnak' => ['datavalue' => ['value' => $image]]]];
        }

        return ['claims' => $claims];
    }

    /** Réponse Commons « imageinfo » d'un fichier. */
    private function imageInfo(string $thumb): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['query' => ['pages' => [['imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => $thumb.'?utm_source=commons']]]]]]);
    }

    public function test_prend_l_image_principale_wikidata_de_la_bonne_localite(): void
    {
        $this->fakeServices([
            'titles=Brooklyn' => Http::response(['entities' => [
                'Q1' => $this->entity(null, null), // page d'homonymie
                'Q2' => $this->entity([41.73, -92.44], 'Brooklyn Iowa.jpg'), // Brooklyn (Iowa) : trop loin
                'Q3' => $this->entity([40.65, -73.95], 'Bedstuybrownstone1.jpg'),
            ]]),
            'File:Bedstuybrownstone1.jpg' => $this->imageInfo('https://upload.wikimedia.org/wikipedia/commons/thumb/3/34/Bedstuybrownstone1.jpg/1280px-Bedstuybrownstone1.jpg'),
        ]);

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/3/34/Bedstuybrownstone1.jpg/1280px-Bedstuybrownstone1.jpg',
            app(PlacePhotoService::class)->photoFor(40.6526, -73.9497, 'Brooklyn, New York, États-Unis')
        );
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'wikipedia.org') || str_contains($r->url(), 'nominatim'));
    }

    public function test_darmstadt_n_est_pas_pris_pour_un_blason(): void
    {
        // « arms » est dans d-ARMS-tadt : seuls les mots entiers comptent.
        $this->fakeServices([
            'titles=Darmstadt' => Http::response(['entities' => ['Q2973' => $this->entity([49.87, 8.65], 'Luisenplatz, Darmstadt.jpg')]]),
            'File:Luisenplatz, Darmstadt.jpg' => $this->imageInfo('https://upload.wikimedia.org/luisenplatz.jpg'),
        ]);

        $this->assertSame('https://upload.wikimedia.org/luisenplatz.jpg', app(PlacePhotoService::class)->photoFor(49.873, 8.651, 'Darmstadt, Allemagne'));
    }

    public function test_un_nom_ambigu_passe_par_la_commune_du_point(): void
    {
        // « Trèves » n'est qu'une page d'homonymie ; Nominatim sait que le
        // point est dans Trier (Q3138), dont l'un des noms est « Trèves ».
        $this->fakeServices([
            'titles=Trèves' => Http::response(['entities' => ['Q350719' => $this->entity(null, null)]]),
            'nominatim.openstreetmap.org/reverse' => Http::response([
                'name' => 'Trier',
                'extratags' => ['wikidata' => 'Q3138'],
                'namedetails' => ['name' => 'Trier', 'name:fr' => 'Trèves'],
            ]),
            'ids=Q3138' => Http::response(['entities' => ['Q3138' => $this->entity([49.75, 6.64], 'Porta Nigra Trier.jpg')]]),
            'File:Porta Nigra Trier.jpg' => $this->imageInfo('https://upload.wikimedia.org/porta-nigra.jpg'),
        ]);

        $this->assertSame('https://upload.wikimedia.org/porta-nigra.jpg', app(PlacePhotoService::class)->photoFor(49.76, 6.644, 'Trèves, Allemagne'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'wikipedia.org'));
    }

    public function test_photo_voisine_retenue_seulement_si_elle_nomme_le_lieu(): void
    {
        // Rotenfels : sur Wikidata et Wikipédia, c'est une falaise à 120 km ;
        // la commune du point est Gaggenau. Près du point, un portrait et une
        // photo de Bad Rotenfels : seule la seconde parle du lieu.
        $this->fakeServices([
            'titles=Rotenfels' => Http::response(['entities' => ['Q1' => $this->entity([49.82, 7.82], 'Rotenfels Felsen.jpg')]]),
            'nominatim.openstreetmap.org/reverse' => Http::response([
                'name' => 'Gaggenau', 'extratags' => ['wikidata' => 'Q504451'], 'namedetails' => ['name' => 'Gaggenau'],
            ]),
            'page/summary/Rotenfels' => Http::response([
                'type' => 'standard',
                'coordinates' => ['lat' => 49.82, 'lon' => 7.82],
                'originalimage' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/1/12/Felsen.jpg', 'width' => 800],
            ]),
            'generator=geosearch' => Http::response(['query' => ['pages' => [
                ['title' => 'File:Mariame Ndiaye.jpg', 'imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/portrait.jpg']]],
                ['title' => 'File:Bad Rotenfels - panoramio (3).jpg', 'imageinfo' => [['mime' => 'image/jpeg', 'url' => 'https://upload.wikimedia.org/bad-rotenfels.jpg']]],
            ]]]),
        ]);

        $this->assertSame('https://upload.wikimedia.org/bad-rotenfels.jpg', app(PlacePhotoService::class)->photoFor(48.8139, 8.306, 'Rotenfels, Gaggenau, Allemagne'));
    }

    public function test_a_defaut_la_photo_de_la_commune_qui_contient_le_lieu(): void
    {
        $this->fakeServices([
            'nominatim.openstreetmap.org/reverse' => Http::response([
                'name' => 'Gaggenau', 'extratags' => ['wikidata' => 'Q504451'], 'namedetails' => ['name' => 'Gaggenau'],
            ]),
            'generator=geosearch' => Http::response(['query' => ['pages' => [
                ['title' => 'File:VIS2015 awards.jpg', 'imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/awards.jpg']]],
            ]]]),
            'ids=Q504451' => Http::response(['entities' => ['Q504451' => $this->entity([48.8, 8.33], 'Gaggenau Rathaus.jpg')]]),
            'File:Gaggenau Rathaus.jpg' => $this->imageInfo('https://upload.wikimedia.org/gaggenau.jpg'),
        ]);

        $this->assertSame('https://upload.wikimedia.org/gaggenau.jpg', app(PlacePhotoService::class)->photoFor(48.8139, 8.306, 'Rotenfels, Gaggenau, Allemagne'));
    }

    public function test_rien_de_sur_ne_donne_rien(): void
    {
        $this->fakeServices([
            'fr.wikipedia.org' => Http::response(['type' => 'disambiguation']),
            'generator=geosearch' => Http::response(['query' => ['pages' => [
                ['title' => 'File:SBS launched on the B44.jpg', 'imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/bus.jpg']]],
                ['title' => 'File:Croix carte postale.jpg', 'imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/carte.jpg']]],
            ]]]),
        ]);

        $this->assertNull(app(PlacePhotoService::class)->photoFor(50.678, 3.149, 'Croix, Nord'));
        $this->assertTrue(app(PlacePhotoService::class)->isResolved(50.678, 3.149, 'Croix, Nord'));
    }

    public function test_un_echec_passager_n_est_pas_memorise(): void
    {
        $calls = 0;
        $this->fakeServices([
            'titles=Lille' => function () use (&$calls) {
                return ++$calls === 1
                    ? Http::response([], 429)
                    : Http::response(['entities' => ['Q648' => $this->entity([50.63, 3.06], 'Lille vue gd place.JPG')]]);
            },
            'File:Lille vue gd place.JPG' => $this->imageInfo('https://upload.wikimedia.org/lille.jpg'),
        ]);
        $service = app(PlacePhotoService::class);

        $this->assertNull($service->photoFor(50.63, 3.06, 'Lille, Nord'));
        $this->assertFalse($service->isResolved(50.63, 3.06, 'Lille, Nord'));

        // Wikidata répond de nouveau : la photo arrive.
        $this->assertSame('https://upload.wikimedia.org/lille.jpg', $service->photoFor(50.63, 3.06, 'Lille, Nord'));
    }

    public function test_image_wikipedia_geante_ramenee_a_1280_et_petit_original_garde(): void
    {
        $this->fakeServices([
            'page/summary/Munich' => Http::response([
                'type' => 'standard',
                'originalimage' => [
                    'source' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/5/59/Munchen_collage.jpg/3840px-Munchen_collage.jpg?utm_source=fr.wikipedia.org',
                    'width' => 3840,
                ],
            ]),
            'page/summary/Valff' => Http::response([
                'type' => 'standard',
                'originalimage' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/3/34/Valff_084.JPG?utm_source=fr.wikipedia.org', 'width' => 800],
            ]),
        ]);
        $service = app(PlacePhotoService::class);

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/5/59/Munchen_collage.jpg/1280px-Munchen_collage.jpg',
            $service->photoFor(48.14, 11.58, 'Munich, Bavière')
        );
        $this->assertSame('https://upload.wikimedia.org/wikipedia/commons/3/34/Valff_084.JPG', $service->photoFor(48.42, 7.52, 'Valff, Bas-Rhin'));
    }

    public function test_s_identifie_aupres_de_wikimedia_avec_l_adresse_du_site(): void
    {
        config(['app.url' => 'https://exemple.test', 'mail.from.address' => 'contact@exemple.test']);
        $this->fakeServices([]);

        app(PlacePhotoService::class)->photoFor(1.0, 1.0, 'Agonac');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'wikidata.org')
            && $r->header('User-Agent')[0] === 'MemoryLane/1.3 (https://exemple.test; contact@exemple.test)');
    }

    public static function places(): array
    {
        return [
            ['Agonac, Dordogne, France', 'Agonac'],
            ['66 rue de Paris, 93100 Montreuil', 'Montreuil'],
            ['23 rue de picpus 75012 Paris', 'Paris'],
            ['57500 St-Avold', 'St-Avold'],
            ['BRUGES      Belgique', 'Bruges'],
            ['Berne en Suisse', 'Berne'],
            ['Paris-Xème, 75110, Paris, Île-de-France, France', 'Paris'],
            ['Metz (cathédrale Saint-Étienne)', 'Metz'],
            ['Valff, 67504, Bas-Rhin, Alsace, France', 'Valff'],
        ];
    }

    #[DataProvider('places')]
    public function test_localite_tiree_du_lieu_saisi(string $place, string $locality): void
    {
        $this->assertSame($locality, PlacePhotoService::locality($place));
    }
}
