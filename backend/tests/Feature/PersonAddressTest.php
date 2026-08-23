<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Services\GeocodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Adresse de résidence des personnes : géocodage BAN au save, visibilité
 * restreinte sur la fiche (propriétaire/admin, ou foyer avec opt-in) et
 * couche heatmap grossière (~1 km) sans identité sur la carte.
 */
class PersonAddressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api-adresse.data.gouv.fr/*' => Http::response([
                'features' => [[
                    'geometry' => ['coordinates' => [2.441441, 48.860642]],
                    'properties' => ['city' => 'Montreuil'],
                ]],
            ]),
        ]);
    }

    private function makePersonWithAddress(User $owner, ?User $linkedUser = null): Person
    {
        $person = Person::factory()->create([
            'user_id' => $owner->id,
            'address' => '12 rue des Lilas, 93100 Montreuil',
        ]);

        $linkedUser?->update(['person_id' => $person->id]);

        return $person;
    }

    // ---------------------------------------------------------------- géocodage

    public function test_adresse_geocodee_ban_au_save(): void
    {
        $owner = User::factory()->create();
        $person = $this->makePersonWithAddress($owner)->fresh();

        $this->assertSame('Montreuil', $person->address_city);
        $this->assertEqualsWithDelta(48.860642, (float) $person->address_latitude, 0.000001);
        $this->assertEqualsWithDelta(2.441441, (float) $person->address_longitude, 0.000001);
    }

    public function test_effacer_l_adresse_nullifie_le_geocodage(): void
    {
        $owner = User::factory()->create();
        $person = $this->makePersonWithAddress($owner);

        $person->update(['address' => null]);
        $person->refresh();

        $this->assertNull($person->address_city);
        $this->assertNull($person->address_latitude);
        $this->assertNull($person->address_longitude);
    }

    public function test_geocodage_ban_utilise_le_cache(): void
    {
        Cache::flush();
        $service = app(GeocodeService::class);

        $service->addressFor('12 rue des Lilas, 93100 Montreuil');
        $service->addressFor('12 rue des Lilas, 93100 Montreuil');

        Http::assertSentCount(1);
    }

    // ---------------------------------------------------------------- fiche

    public function test_adresse_invisible_pour_un_connecte_lambda(): void
    {
        $owner = User::factory()->create();
        $person = $this->makePersonWithAddress($owner);

        $response = $this->actingAs(User::factory()->create())
            ->getJson("/people/{$person->id}");

        $response->assertOk();
        $this->assertArrayNotHasKey('address', $response->json('person'));
        $this->assertArrayNotHasKey('address_latitude', $response->json('person'));
    }

    public function test_adresse_visible_pour_le_proprietaire_et_l_admin(): void
    {
        $owner = User::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $person = $this->makePersonWithAddress($owner);

        foreach ([$owner, $admin] as $viewer) {
            $response = $this->actingAs($viewer)->getJson("/people/{$person->id}");
            $response->assertOk()
                ->assertJsonPath('person.address', '12 rue des Lilas, 93100 Montreuil');
        }
    }

    public function test_adresse_visible_du_foyer_seulement_avec_opt_in(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $household = Household::factory()->create(['created_by' => $owner->id]);
        $household->members()->attach([$owner->id, $member->id]);

        $person = $this->makePersonWithAddress($owner, $owner);

        // Sans opt-in : invisible pour le co-membre du foyer.
        $response = $this->actingAs($member)->getJson("/people/{$person->id}");
        $this->assertArrayNotHasKey('address', $response->json('person'));

        // Avec opt-in : visible.
        $owner->update(['preferences' => ['share_address_with_household' => true]]);
        $response = $this->actingAs($member)->getJson("/people/{$person->id}");
        $response->assertJsonPath('person.address', '12 rue des Lilas, 93100 Montreuil');
    }

    public function test_opt_in_sans_foyer_commun_ne_montre_rien(): void
    {
        $owner = User::factory()->create(['preferences' => ['share_address_with_household' => true]]);
        $stranger = User::factory()->create();
        $person = $this->makePersonWithAddress($owner, $owner);

        $response = $this->actingAs($stranger)->getJson("/people/{$person->id}");
        $this->assertArrayNotHasKey('address', $response->json('person'));
    }

    public function test_l_index_des_personnes_ne_fuite_jamais_l_adresse(): void
    {
        $owner = User::factory()->create();
        $this->makePersonWithAddress($owner);

        $response = $this->actingAs($owner)->getJson('/people');

        $response->assertOk();
        $this->assertStringNotContainsString('rue des Lilas', $response->getContent());
        $this->assertStringNotContainsString('address_latitude', $response->getContent());
    }

    // ---------------------------------------------------------------- profil

    public function test_le_profil_persiste_la_preference_de_partage(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/profile', [
            'name' => $user->name,
            'email' => $user->email,
            'share_address_with_household' => true,
        ])->assertRedirect();

        $this->assertTrue($user->fresh()->sharesAddressWithHousehold());
    }

    // ---------------------------------------------------------------- heatmap

    public function test_heatmap_renvoie_des_points_arrondis_sans_identite(): void
    {
        $owner = User::factory()->create();
        $this->makePersonWithAddress($owner);

        $response = $this->actingAs(User::factory()->create())->getJson('/map/heatmap');

        $response->assertOk();
        $points = $response->json('points');
        $this->assertCount(1, $points);
        [$lat, $lng, $weight] = $points[0];
        $this->assertSame(48.86, $lat);
        $this->assertSame(2.44, $lng);
        $this->assertSame(1, $weight);
        $this->assertStringNotContainsString('rue des Lilas', $response->getContent());
    }

    public function test_heatmap_agrege_les_points_proches(): void
    {
        $owner = User::factory()->create();
        $this->makePersonWithAddress($owner);
        $this->makePersonWithAddress($owner);

        $response = $this->actingAs($owner)->getJson('/map/heatmap');

        $points = $response->json('points');
        $this->assertCount(1, $points);
        $this->assertSame(2, $points[0][2]);
    }

    public function test_heatmap_exige_l_authentification(): void
    {
        $this->getJson('/map/heatmap')->assertUnauthorized();
    }
}
