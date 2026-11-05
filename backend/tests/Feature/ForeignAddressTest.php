<?php

namespace Tests\Feature;

use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adresses hors de France : la BAN ne connaît que la France et plaçait
 * « Palma de Mallorca -España- » à Castelnou (Pyrénées-Orientales).
 */
class ForeignAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['memorylane.nominatim_interval' => 0]);
        Http::fake([
            'api-adresse.data.gouv.fr/*' => fn (Request $r) => str_contains($r['q'], 'Lilas')
                ? Http::response(['features' => [['geometry' => ['coordinates' => [2.44, 48.86]], 'properties' => ['city' => 'Montreuil', 'score' => 0.9]]]])
                : Http::response(['features' => [['geometry' => ['coordinates' => [2.69, 42.62]], 'properties' => ['city' => 'Castelnou', 'score' => 0.31]]]]),
            'nominatim.openstreetmap.org/*' => Http::response([['lat' => '39.5696', 'lon' => '2.6502', 'display_name' => 'Palma, Illes Balears, España']]),
        ]);
    }

    private function person(string $address): Person
    {
        return Person::factory()->create(['user_id' => User::factory()->create()->id, 'address' => $address])->fresh();
    }

    public function test_adresse_a_l_etranger_localisee_dans_le_monde(): void
    {
        $p = $this->person('Palma de Mallorca -España-');

        $this->assertEqualsWithDelta(39.57, (float) $p->address_latitude, 0.01);
        $this->assertEqualsWithDelta(2.65, (float) $p->address_longitude, 0.01);
        $this->assertSame('Palma de Mallorca', $p->address_city);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api-adresse'));
    }

    public function test_reponse_ban_trop_vague_remplacee_par_le_monde(): void
    {
        $p = $this->person('Calle Mayor 3, Palma');

        $this->assertEqualsWithDelta(39.57, (float) $p->address_latitude, 0.01);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'api-adresse'));
    }

    public function test_adresse_francaise_toujours_par_la_ban(): void
    {
        $p = $this->person('12 rue des Lilas, 93100 Montreuil');

        $this->assertSame('Montreuil', $p->address_city);
        $this->assertEqualsWithDelta(48.86, (float) $p->address_latitude, 0.01);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'nominatim'));
    }
}
