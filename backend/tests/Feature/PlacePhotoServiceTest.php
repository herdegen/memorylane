<?php

namespace Tests\Feature;

use App\Services\PlacePhotoService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Photo d'un lieu : image de l'article Wikipédia de la localité d'abord,
 * géo-recherche Commons en repli.
 */
class PlacePhotoServiceTest extends TestCase
{
    public function test_prend_l_image_de_l_article_wikipedia_en_vignette(): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response(['entities' => []]),
            'fr.wikipedia.org/api/rest_v1/page/summary/Agonac' => Http::response([
                'type' => 'standard',
                'originalimage' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/e/ea/Agonac_%C3%A9glise_Saint-Martin_%289%29.JPG'],
            ]),
            'commons.wikimedia.org/*' => Http::response([]),
        ]);

        $url = app(PlacePhotoService::class)->photoFor(45.293, 0.754, 'Agonac, Dordogne, France');

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/e/ea/Agonac_%C3%A9glise_Saint-Martin_%289%29.JPG/1280px-Agonac_%C3%A9glise_Saint-Martin_%289%29.JPG',
            $url
        );
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'commons.wikimedia.org'));
    }

    public function test_ignore_cartes_et_drapeaux_et_se_rabat_sur_commons(): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response(['entities' => []]),
            'fr.wikipedia.org/api/rest_v1/page/summary/Chili' => Http::response([
                'type' => 'standard',
                'originalimage' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/1/12/Chile_location_map.svg'],
            ]),
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
                ['imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/voisin.jpg']]],
            ]]]),
        ]);

        $this->assertSame('https://upload.wikimedia.org/voisin.jpg', app(PlacePhotoService::class)->photoFor(-31.7, -71.3, 'Chili'));
    }

    public function test_page_d_homonymie_ou_absente_ne_donne_rien(): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response(['entities' => []]),
            'fr.wikipedia.org/*' => Http::response(['type' => 'disambiguation'], 200),
            'commons.wikimedia.org/*' => Http::response([]),
        ]);

        $this->assertNull(app(PlacePhotoService::class)->photoFor(1.0, 1.0, 'Croix, Nord'));
    }

    public function test_un_echec_passager_n_est_pas_memorise(): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response(['entities' => []]),
            'fr.wikipedia.org/*' => Http::sequence()
                ->push([], 429)
                ->push(['type' => 'standard', 'originalimage' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/3/3b/Lille_Collage.jpg', 'width' => 3000]]),
            'commons.wikimedia.org/*' => Http::sequence()->push([], 503),
        ]);
        $service = app(PlacePhotoService::class);

        $this->assertNull($service->photoFor(50.63, 3.06, 'Lille, Nord'));
        $this->assertFalse($service->isResolved(50.63, 3.06, 'Lille, Nord'));

        // Wikipédia répond de nouveau : la photo arrive.
        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3b/Lille_Collage.jpg/1280px-Lille_Collage.jpg',
            $service->photoFor(50.63, 3.06, 'Lille, Nord')
        );
    }

    public function test_vignette_geante_ramenee_a_1280_et_petit_original_garde(): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response(['entities' => []]),
            'fr.wikipedia.org/api/rest_v1/page/summary/Munich' => Http::response([
                'type' => 'standard',
                'originalimage' => [
                    'source' => 'https://thumb.wikimedia.org/wikipedia/commons/thumb/5/59/Munchen_collage.jpg/3840px-Munchen_collage.jpg?utm_source=fr.wikipedia.org&utm_campaign=api',
                    'width' => 3840,
                ],
            ]),
            'fr.wikipedia.org/api/rest_v1/page/summary/Valff' => Http::response([
                'type' => 'standard',
                'originalimage' => ['source' => 'https://upload.wikimedia.org/wikipedia/commons/3/34/Valff_084.JPG?utm_source=fr.wikipedia.org', 'width' => 800],
            ]),
        ]);
        $service = app(PlacePhotoService::class);

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/5/59/Munchen_collage.jpg/1280px-Munchen_collage.jpg',
            $service->photoFor(48.14, 11.58, 'Munich, Bavière')
        );
        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/3/34/Valff_084.JPG',
            $service->photoFor(48.42, 7.52, 'Valff, Bas-Rhin')
        );
    }

    public function test_s_identifie_aupres_de_wikipedia_avec_l_adresse_du_site(): void
    {
        config(['app.url' => 'https://exemple.test', 'mail.from.address' => 'contact@exemple.test']);
        Http::fake(['*' => Http::response(['type' => 'disambiguation'])]);

        app(PlacePhotoService::class)->photoFor(1.0, 1.0, 'Agonac');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'wikipedia.org')
            && $r->header('User-Agent')[0] === 'MemoryLane/1.3 (https://exemple.test; contact@exemple.test)');
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

    public function test_prend_l_image_principale_wikidata_de_la_bonne_localite(): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response(['entities' => [
                'Q1' => $this->entity(null, null), // page d'homonymie
                'Q2' => $this->entity([41.73, -92.44], 'Brooklyn Iowa.jpg'), // Brooklyn (Iowa) : trop loin
                'Q3' => $this->entity([40.65, -73.95], 'Bedstuybrownstone1.jpg'),
            ]]),
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => [
                ['imageinfo' => [['mime' => 'image/jpeg', 'thumburl' => 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/34/Bedstuybrownstone1.jpg/1280px-Bedstuybrownstone1.jpg?utm_source=commons']]],
            ]]]),
        ]);

        $this->assertSame(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/3/34/Bedstuybrownstone1.jpg/1280px-Bedstuybrownstone1.jpg',
            app(PlacePhotoService::class)->photoFor(40.6526, -73.9497, 'Brooklyn, New York, États-Unis')
        );
        Http::assertSent(fn ($r) => str_contains($r->url(), 'File%3ABedstuybrownstone1.jpg'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'wikipedia.org'));
    }

    public function test_un_homonyme_lointain_n_est_pas_pris(): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response(['entities' => [
                'Q1' => $this->entity([46.56, 3.33], 'Moulins Allier.jpg'), // Moulins (Allier)
            ]]),
            'fr.wikipedia.org/*' => Http::response(['type' => 'disambiguation']),
            'commons.wikimedia.org/*' => Http::response(['query' => ['pages' => []]]),
        ]);

        // Moulins-lès-Metz, à plus de 300 km : ni l'image de l'Allier, ni rien d'autre.
        $this->assertNull(app(PlacePhotoService::class)->photoFor(49.10, 6.11, 'Moulins, Moselle'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'Moulins+Allier') || str_contains($r->url(), 'Moulins%20Allier'));
    }
}
