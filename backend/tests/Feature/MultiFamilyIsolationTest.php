<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Services\GeocodeService;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Plusieurs familles sur la même installation : chacune chez elle. Une fiche
 * de famille appartient à ses cercles famille ; une fiche commune (ancêtre
 * partagé) est rattachée aux deux et visible des deux côtés.
 *
 * Famille A (Alice) : Anne (grand-mère) → Arthur.
 * Famille B (Bruno) : Bernard → Béatrice.
 * Fiche commune : Ulysse, père d'Anne (côté A) et de Bernard (côté B).
 */
class MultiFamilyIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bruno;

    private Household $A;

    private Household $B;

    private Person $ulysse;

    private Person $anne;

    private Person $arthur;

    private Person $bernard;

    private Person $beatrice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alice = User::factory()->create(['name' => 'Alice A']);
        $this->bruno = User::factory()->create(['name' => 'Bruno B']);
        $this->A = Household::factory()->create(['name' => 'Famille A', 'created_by' => $this->alice->id]);
        $this->B = Household::factory()->create(['name' => 'Famille B', 'created_by' => $this->bruno->id]);
        $this->A->members()->attach($this->alice->id);
        $this->B->members()->attach($this->bruno->id);

        // Chacun crée sa famille dans son coin (rattachement automatique).
        $this->actingAs($this->alice);
        $this->ulysse = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Ulysse Ancêtre', 'birth_date' => '1850-01-01']);
        $this->anne = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Anne Alpha', 'father_id' => $this->ulysse->id, 'birth_date' => '1880-01-01']);
        $this->arthur = Person::factory()->create(['user_id' => $this->alice->id, 'name' => 'Arthur Alpha', 'mother_id' => $this->anne->id, 'birth_date' => '1910-01-01']);
        $this->actingAs($this->bruno);
        $this->bernard = Person::factory()->create(['user_id' => $this->bruno->id, 'name' => 'Bernard Bravo', 'father_id' => $this->ulysse->id, 'birth_date' => '1882-01-01']);
        $this->beatrice = Person::factory()->create(['user_id' => $this->bruno->id, 'name' => 'Béatrice Bravo', 'father_id' => $this->bernard->id, 'birth_date' => '1912-01-01']);
        auth()->logout();

        // L'ancêtre commun : partagé avec la famille B.
        $this->B->people()->attach($this->ulysse->id, ['added_by' => $this->alice->id]);
    }

    private function ids($response, ?string $key = null)
    {
        return collect($key ? $response->json($key) : $response->json())->pluck('id');
    }

    public function test_les_nouvelles_fiches_entrent_dans_la_famille_de_leur_auteur(): void
    {
        $this->assertSame([$this->A->id], $this->arthur->circles()->pluck('households.id')->all());
        $this->assertSame([$this->B->id], $this->beatrice->circles()->pluck('households.id')->all());
        $this->assertEqualsCanonicalizing([$this->A->id, $this->B->id], $this->ulysse->circles()->pluck('households.id')->all());
    }

    public function test_fiche_d_une_autre_famille_introuvable(): void
    {
        $this->actingAs($this->alice)->getJson("/people/{$this->beatrice->id}/timeline")->assertNotFound();
        $this->actingAs($this->alice)->get("/people/{$this->bernard->id}")->assertNotFound();
        $this->actingAs($this->bruno)->getJson("/people/{$this->arthur->id}/timeline")->assertNotFound();

        // La fiche commune : des deux côtés.
        $this->actingAs($this->alice)->getJson("/people/{$this->ulysse->id}/timeline")->assertOk();
        $this->actingAs($this->bruno)->getJson("/people/{$this->ulysse->id}/timeline")->assertOk();
    }

    public function test_liste_arbre_et_recherche_restent_dans_la_famille(): void
    {
        $people = $this->ids($this->actingAs($this->alice)->getJson('/people')->assertOk());
        $this->assertEqualsCanonicalizing([$this->ulysse->id, $this->anne->id, $this->arthur->id], $people->all());

        $tree = $this->ids($this->actingAs($this->bruno)->getJson('/family-tree/data')->assertOk());
        $this->assertEqualsCanonicalizing([$this->ulysse->id, $this->bernard->id, $this->beatrice->id], $tree->all());

        $found = $this->ids($this->actingAs($this->alice)->getJson('/search?q=Bravo')->assertOk(), 'people');
        $this->assertSame([], $found->all());
        $found = $this->ids($this->actingAs($this->alice)->getJson('/search?q=Ulysse')->assertOk(), 'people');
        $this->assertSame([$this->ulysse->id], $found->all());
    }

    public function test_la_fiche_commune_ne_montre_que_sa_propre_descendance(): void
    {
        $this->actingAs($this->alice)->get("/people/{$this->ulysse->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Show', false)
                ->where('children', fn ($children) => collect($children)->pluck('id')->all() === [$this->anne->id]));

        // Le lien de parenté ne traverse pas l'autre famille.
        $this->actingAs($this->alice)->getJson("/people/{$this->bernard->id}/kinship")->assertNotFound();
    }

    public function test_carte_dans_le_temps_limitee_a_sa_famille(): void
    {
        $this->alice->forceFill(['person_id' => $this->arthur->id])->save();
        foreach ([$this->ulysse, $this->anne, $this->arthur, $this->bernard, $this->beatrice] as $p) {
            $p->forceFill(['birth_place' => 'Lyon'])->save();
        }
        Cache::put(GeocodeService::placeKey('Lyon'), ['latitude' => 45.76, 'longitude' => 4.84], now()->addDay());

        $tracks = $this->actingAs($this->alice->fresh())->getJson('/map/heatmap/timeline')->assertOk()->json('tracks');
        // Ulysse, Anne, Arthur — pas Bernard ni Béatrice.
        $this->assertCount(3, $tracks);
    }

    public function test_on_n_entre_pas_dans_la_famille_d_un_autre(): void
    {
        // Bruno ne peut ni s'ajouter à la famille A, ni y ajouter quelqu'un.
        $this->actingAs($this->bruno)->postJson("/households/{$this->A->id}/members", ['user_id' => $this->bruno->id])->assertForbidden();
        $this->A->makeWelcome();
        $this->actingAs($this->bruno)->postJson("/households/{$this->A->id}/members", ['user_id' => $this->bruno->id])->assertForbidden();
        $this->assertFalse($this->A->isMember($this->bruno));

        // Ni y ranger une de ses fiches.
        $this->actingAs($this->bruno)->postJson("/people/{$this->beatrice->id}/circles", ['household_id' => $this->A->id])->assertForbidden();
    }

    public function test_une_invitation_mene_a_la_famille_de_qui_invite(): void
    {
        Mail::fake();
        $this->A->makeWelcome(); // le cercle d'accueil est celui d'une autre famille

        $this->actingAs($this->bruno);
        $cousin = Person::factory()->create(['user_id' => $this->bruno->id, 'name' => 'Cousin Bravo', 'birth_date' => '1990-05-01', 'death_date' => null]);
        $invitation = app(InvitationService::class)->invite($this->bruno, 'cousin@example.fr', $cousin);

        $this->assertSame($this->B->id, $invitation->household_id);
    }

    public function test_partager_une_fiche_avec_une_autre_famille_dont_on_est_membre(): void
    {
        // Carla est des deux familles : elle partage Arthur avec la famille B.
        $carla = User::factory()->create();
        $this->A->members()->attach($carla->id);
        $this->B->members()->attach($carla->id);

        $this->actingAs($carla)->postJson("/people/{$this->arthur->id}/circles", ['household_id' => $this->B->id])->assertOk();

        $this->actingAs($this->bruno)->getJson("/people/{$this->arthur->id}/timeline")->assertOk();
    }

    public function test_on_ne_retire_pas_un_parent_qu_on_ne_voit_pas(): void
    {
        // Fiche commune dont la famille B a renseigné le père (invisible pour A).
        $this->actingAs($this->bruno);
        $child = Person::factory()->create(['user_id' => $this->bruno->id, 'name' => 'Enfant Commun', 'father_id' => $this->bernard->id]);
        $this->A->people()->attach($child->id);

        $this->actingAs($this->alice)->deleteJson("/people/{$child->id}/parent", ['parent_type' => 'father'])->assertForbidden();
        $this->assertSame($this->bernard->id, $child->fresh()->father_id);
    }

    public function test_installation_sans_cercle_famille_repertoire_commun(): void
    {
        Household::query()->delete();
        $loner = User::factory()->create();

        // Une seule famille, d'avant les cercles : tout le monde voit tout.
        $this->actingAs($loner)->getJson("/people/{$this->beatrice->id}/timeline")->assertOk();
    }

    public function test_le_premier_cercle_famille_reprend_les_fiches_sans_famille(): void
    {
        Household::query()->delete();
        $orphan = Person::factory()->create(['name' => 'Fiche Ancienne']);
        $this->assertSame(0, $orphan->circles()->count());

        $first = Household::factory()->create(['created_by' => $this->alice->id]);

        $this->assertTrue($orphan->circles()->whereKey($first->id)->exists());
    }
}
