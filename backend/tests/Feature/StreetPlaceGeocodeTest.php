<?php

namespace Tests\Feature;

use App\Services\GeocodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Lieu saisi comme une adresse française (« 66 rue de Paris, Montreuil ») :
 * la BAN d'abord — Nominatim avait choisi Montreuil-sur-Mer.
 */
class StreetPlaceGeocodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['memorylane.nominatim_interval' => 0]);
        Http::fake([
            'api-adresse.data.gouv.fr/*' => Http::response(['features' => [['geometry' => ['coordinates' => [2.4396, 48.8597]], 'properties' => ['city' => 'Montreuil', 'score' => 0.86]]]]),
            'nominatim.openstreetmap.org/*' => Http::response([['lat' => '50.4640', 'lon' => '1.7633']]),
        ]);
    }

    public function test_adresse_de_rue_par_la_ban(): void
    {
        $c = app(GeocodeService::class)->coordinatesFor('66 rue de Paris, Montreuil');

        $this->assertEqualsWithDelta(48.86, $c['latitude'], 0.01);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'nominatim'));
    }

    public function test_ville_seule_toujours_par_nominatim(): void
    {
        app(GeocodeService::class)->coordinatesFor('Lambersart, France');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api-adresse'));
    }

    public function test_reconnaissance_des_adresses_de_rue(): void
    {
        $this->assertTrue(GeocodeService::looksLikeFrenchStreet('66 rue de Paris, Montreuil'));
        $this->assertTrue(GeocodeService::looksLikeFrenchStreet('18 rue du renard 93460 Gournay sur marne'));
        $this->assertTrue(GeocodeService::looksLikeFrenchStreet('55 Boulevard de Charonne'));
        $this->assertFalse(GeocodeService::looksLikeFrenchStreet('Agonac (Chaberlin), Dordogne, France'));
        $this->assertFalse(GeocodeService::looksLikeFrenchStreet('12 Calle Mayor, Palma, España'));
    }
}
