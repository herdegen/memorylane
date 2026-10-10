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
}
