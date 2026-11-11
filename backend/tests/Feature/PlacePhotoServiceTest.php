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
            'fr.wikipedia.org/*' => Http::response(['type' => 'disambiguation'], 200),
            'commons.wikimedia.org/*' => Http::response([]),
        ]);

        $this->assertNull(app(PlacePhotoService::class)->photoFor(1.0, 1.0, 'Croix, Nord'));
    }

    public function test_un_echec_passager_n_est_pas_memorise(): void
    {
        Http::fake([
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
}
