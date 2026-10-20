<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\TvDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Appli télévision : appairage par code validé depuis le téléphone, session
 * TV en lecture seule (sauf le jeu), révocation, reconnexion par jeton,
 * cercle propre à la TV, et les écrans eux-mêmes.
 */
class TvTest extends TestCase
{
    use RefreshDatabase;

    private User $me;
    private Household $family;
    private Household $friends;
    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();
        $this->me = User::factory()->create();
        $this->family = Household::factory()->create(['created_by' => $this->me->id]);
        $this->family->members()->attach($this->me->id);
        $this->friends = Household::factory()->friends()->create(['created_by' => $this->me->id]);
        $this->friends->members()->attach($this->me->id);
        $this->me->forceFill(['current_household_id' => $this->family->id])->save();

        $this->album = Album::factory()->create(['user_id' => $this->me->id, 'name' => 'Vacances']);
        foreach (range(1, 3) as $i) {
            $m = Media::factory()->photo()->create(['user_id' => $this->me->id, 'taken_at' => now()->subYears(5)->subDays($i)]);
            $this->album->media()->attach($m->id);
        }
    }

    /** Code affiché par la TV, validé depuis le téléphone, récupéré par la TV. */
    private function pairTv(): TvDevice
    {
        $code = $this->postJson('/tv/ecran/code')->assertOk()->json('code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $tvSession = session()->all();

        $this->getJson('/tv/ecran/code')->assertJson(['status' => 'waiting']);

        // Téléphone : autre session, connecté.
        $this->flushSession();
        $this->actingAs($this->me)->post('/tv', ['code' => $code, 'name' => 'Salon'])
            ->assertSessionHasNoErrors()->assertRedirect();
        Auth::logout();

        // TV : reprend sa session et interroge.
        $this->withSession($tvSession);
        $this->getJson('/tv/ecran/code')
            ->assertJson(['status' => 'linked'])
            ->assertCookie(TvDevice::COOKIE);
        $this->assertAuthenticatedAs($this->me);

        return TvDevice::firstOrFail();
    }

    public function test_appairage_par_code(): void
    {
        $device = $this->pairTv();

        $this->assertSame($this->me->id, $device->user_id);
        $this->assertSame('Salon', $device->name);
        $this->assertSame($this->family->id, $device->household_id);
        $this->assertNotNull($device->token_hash);
        $this->assertNull($device->pairing_code);
        $this->get('/tv/ecran')->assertRedirect('/tv/ecran/accueil');
    }

    public function test_code_inconnu_ou_expire_refuse(): void
    {
        $this->postJson('/tv/ecran/code')->assertOk();
        TvDevice::query()->update(['pairing_expires_at' => now()->subMinute()]);
        $code = TvDevice::value('pairing_code');

        $this->actingAs($this->me)->post('/tv', ['code' => $code])->assertSessionHasErrors('code');
        $this->actingAs($this->me)->post('/tv', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull(TvDevice::value('user_id'));
    }

    public function test_session_tv_en_lecture_seule(): void
    {
        $this->pairTv();

        $this->get('/tv/ecran/accueil')->assertOk();
        $this->get('/dashboard')->assertRedirect('/tv/ecran/accueil');
        $this->get('/tv')->assertRedirect('/tv/ecran/accueil');
        $this->postJson('/albums', ['name' => 'Piratage'])->assertForbidden();
        $this->deleteJson("/albums/{$this->album->id}")->assertForbidden();
        // Le jeu reste jouable.
        $this->getJson('/quests/faces/next')->assertOk();
        $this->assertSame(1, Album::count());
    }

    public function test_tv_deliee_depuis_le_telephone(): void
    {
        $device = $this->pairTv();
        $tvSession = session()->all();

        $this->flushSession();
        Auth::logout();
        $other = User::factory()->create();
        $this->actingAs($other)->delete("/tv/{$device->id}")->assertNotFound();
        $this->actingAs($this->me)->delete("/tv/{$device->id}")->assertRedirect();
        $this->assertModelMissing($device);
        Auth::logout();

        $this->withSession($tvSession)->actingAs($this->me);
        $this->getJson('/quests/faces/next')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_reconnexion_par_jeton_apres_expiration_de_session(): void
    {
        $device = TvDevice::create(['user_id' => $this->me->id, 'household_id' => $this->friends->id, 'confirmed_at' => now()]);
        $token = TvDevice::newToken();
        $device->forceFill(['token_hash' => TvDevice::hashToken($token)])->save();

        $this->withCookie(TvDevice::COOKIE, $token)
            ->get('/tv/ecran/accueil')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Home', false)
                // Le cercle de la TV, pas celui du compte…
                ->where('circle.id', $this->friends->id));
        $this->assertAuthenticatedAs($this->me);
        $this->assertSame($device->id, session('tv_device_id'));
        // … sans toucher au cercle courant du téléphone.
        $this->assertSame($this->family->id, $this->me->fresh()->current_household_id);

        // Jeton inconnu : pas de connexion.
        Auth::logout();
        $this->flushSession();
        $this->withCookie(TvDevice::COOKIE, 'faux')->get('/tv/ecran/accueil')->assertRedirect('/login');
    }

    public function test_changer_de_cercle_sur_la_tv(): void
    {
        $device = $this->pairTv();

        $this->post('/tv/ecran/cercle', ['household_id' => $this->friends->id])->assertRedirect('/tv/ecran/accueil');
        $this->assertSame($this->friends->id, $device->fresh()->household_id);
        $this->assertSame($this->family->id, $this->me->fresh()->current_household_id);

        $stranger = Household::factory()->create();
        $this->post('/tv/ecran/cercle', ['household_id' => $stranger->id])->assertForbidden();
    }

    public function test_ecrans_tv(): void
    {
        $this->actingAs($this->me);

        $this->get('/tv/ecran/accueil')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Home', false)
                ->where('albums.0.id', $this->album->id)
                ->has('albums.0.previews', 3));
        $this->get('/tv/ecran/albums')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Albums', false)
                ->where('rows', fn ($rows) => collect($rows)->firstWhere('key', 'all')['albums'][0]['id'] === $this->album->id));
        $this->get("/tv/ecran/albums/{$this->album->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Collection', false)
                ->where('title', 'Vacances')
                ->has('items', 3)
                ->where('gameAlbumId', $this->album->id));
        $this->get('/tv/ecran/qui-est-ce')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Game', false));
        $this->get('/tv')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Link', false));

        // Album d'un autre : refusé.
        $foreign = Album::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->get("/tv/ecran/albums/{$foreign->id}")->assertForbidden();
    }

    public function test_photos_par_annee_et_collections(): void
    {
        $this->actingAs($this->me);
        $year = (string) now()->subYears(5)->subDays(1)->year;
        $video = Media::factory()->create(['user_id' => $this->me->id, 'type' => 'video', 'taken_at' => null]);

        $this->get('/tv/ecran/photos')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Photos', false)
                ->where('rows', fn ($rows) => collect($rows)->flatMap(fn ($r) => collect($r['tiles'])->pluck('title'))
                    ->intersect([$year, 'Vidéos', 'Sans date'])->count() === 3));
        $this->get("/tv/ecran/photos/{$year}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Collection', false)->has('items', 3));
        $this->get('/tv/ecran/photos/videos')->assertOk()
            ->assertInertia(fn ($page) => $page->where('items.0.id', $video->id)->where('items.0.type', 'video'));
        $this->get('/tv/ecran/photos/nimporte')->assertNotFound();
    }

    public function test_proches_et_recherche(): void
    {
        $this->actingAs($this->me);
        $mamie = Person::factory()->create(['user_id' => $this->me->id, 'first_name' => 'Suzanne', 'last_name' => 'Martin', 'name' => 'Suzanne Martin']);
        $ancetre = Person::factory()->create(['user_id' => $this->me->id, 'first_name' => 'Suzon', 'last_name' => 'Ancien', 'name' => 'Suzon Ancien']);
        $this->album->media()->first()->people()->attach($mamie->id);

        // Proches : seulement les fiches qui ont des photos.
        $this->get('/tv/ecran/proches')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/People', false)
                ->where('rows', fn ($rows) => ($ids = collect($rows)->flatMap(fn ($r) => collect($r['people'])->pluck('id')))
                    ->contains($mamie->id) && ! $ids->contains($ancetre->id)));
        $this->get("/tv/ecran/proches/{$mamie->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Person', false)->has('items', 1));

        $this->getJson('/tv/ecran/recherche/resultats?q=Suz')->assertOk()
            ->assertJsonPath('people.0.id', $mamie->id)
            ->assertJsonCount(1, 'people');
        $this->getJson('/tv/ecran/recherche/resultats?q=vacan')->assertOk()
            ->assertJsonPath('albums.0.id', $this->album->id);
        $this->getJson('/tv/ecran/recherche/resultats?q=x')->assertUnprocessable();

        $this->getJson('/tv/ecran/cadre')->assertOk()->assertJsonCount(3, 'items');
    }

    public function test_application_android_telechargeable(): void
    {
        $this->get('/apk')->assertRedirect('/tv/application');
        $this->get('/tv/application')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
            ->assertDownload('MemoryLane-TV.apk');
        $this->actingAs($this->me)->get('/tv')
            ->assertInertia(fn ($page) => $page->where('app.version', fn ($v) => is_string($v) && $v !== ''));
    }

    public function test_arbre_a_la_telecommande(): void
    {
        $this->actingAs($this->me);
        $mk = fn (array $a) => Person::factory()->create(['user_id' => $this->me->id, 'in_family_directory' => true] + $a);
        $papa = $mk(['first_name' => 'Paul', 'name' => 'Paul Martin', 'gender' => 'M']);
        $moi = $mk(['first_name' => 'Léa', 'name' => 'Léa Martin', 'gender' => 'F', 'father_id' => $papa->id, 'birth_date' => '1990-01-02']);
        $frere = $mk(['first_name' => 'Tom', 'name' => 'Tom Martin', 'gender' => 'M', 'father_id' => $papa->id]);
        $mari = $mk(['first_name' => 'Hugo', 'name' => 'Hugo Roy', 'gender' => 'M']);
        $fille = $mk(['first_name' => 'Zoé', 'name' => 'Zoé Roy', 'gender' => 'F', 'mother_id' => $moi->id]);
        \App\Models\PersonRelationship::create(['person1_id' => $moi->id, 'person2_id' => $mari->id, 'type' => 'spouse', 'start_date' => '2015-06-20']);
        $this->me->forceFill(['person_id' => $moi->id])->save();

        $this->get('/tv/ecran/arbre')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Tree', false)
                ->where('start.focus.id', $moi->id)
                ->where('start.focus.relation', 'Vous')
                ->where('start.parents.0.id', $papa->id)
                ->where('start.parents.0.relation', 'Votre père')
                ->where('start.siblings.0.id', $frere->id)
                ->where('start.spouses.0.union', 'Mariés en 2015')
                ->where('start.children.0.id', $fille->id)
                ->where('family', true));

        $this->getJson("/tv/ecran/arbre/{$papa->id}/famille")->assertOk()
            ->assertJsonPath('focus.id', $papa->id)
            ->assertJsonCount(2, 'children');

        // Fiche : page dédiée, frise lisible depuis la session TV.
        $this->get("/tv/ecran/proches/{$moi->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Person', false)->where('inTree', true)->where('showLife', true));

        // Cercle d'amis : pas d'arbre.
        $this->me->forceFill(['current_household_id' => $this->friends->id])->save();
        $this->get('/tv/ecran/arbre')->assertNotFound();
    }

    public function test_frise_lisible_par_une_tv(): void
    {
        $this->pairTv();
        $p = Person::factory()->create(['user_id' => $this->me->id, 'in_family_directory' => true, 'birth_date' => '1950-03-04']);
        $this->getJson("/people/{$p->id}/timeline")->assertOk()->assertJsonPath('0.kind', 'birth');
    }

    public function test_carte_par_lieux(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $this->actingAs($this->me);
        foreach ($this->album->media as $i => $m) {
            $m->metadata()->create(['latitude' => 48.65 + $i * 0.001, 'longitude' => -2.01]);
        }
        $paris = Media::factory()->photo()->create(['user_id' => $this->me->id]);
        $paris->metadata()->create(['latitude' => 48.85, 'longitude' => 2.35]);

        $this->get('/tv/ecran/carte')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Map', false)
                ->has('places', 2)
                ->where('places.0.count', 3)
                ->where('places.0.key', '48.7_-2.0')
                // Pas encore de nom de lieu : l'album le plus présent.
                ->where('places.0.name', 'Vacances'));
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ResolvePlaceNames::class);

        $this->get('/tv/ecran/carte/48.7_-2.0?nom=Saint-Malo')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Tv/Collection', false)->has('items', 3)->where('title', 'Saint-Malo'));
        $this->get('/tv/ecran/carte/12.3_4.5')->assertNotFound();
        $this->get('/tv/ecran/carte/nimporte')->assertNotFound();
    }
}
