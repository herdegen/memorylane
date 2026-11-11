<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\TvDevice;
use App\Models\User;
use App\Models\VoiceNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Routes sensibles qu'aucun test n'appelait (bilan des tests 2026-11) :
 * fichier d'un mémo vocal, recherche de comptes pour ouvrir un album, « C'est
 * moi » sur une fiche, « Ce jour-là » et « Délier » de la TV.
 */
class SensitiveRoutesTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private Household $family;

    protected function setUp(): void
    {
        parent::setUp();

        // Alice et Bob partagent un cercle ; Carol est d'une autre famille.
        $this->alice = User::factory()->create(['name' => 'Alice Martin']);
        $this->bob = User::factory()->create(['name' => 'Bob Martin']);
        $this->carol = User::factory()->create(['name' => 'Carole Autre']);
        $this->family = Household::factory()->create(['created_by' => $this->alice->id]);
        $this->family->members()->attach([$this->alice->id, $this->bob->id]);
        $other = Household::factory()->create(['created_by' => $this->carol->id]);
        $other->members()->attach($this->carol->id);
    }

    // --- Mémo vocal : le fichier audio suit les droits de la photo ----------

    public function test_voice_note_file_follows_the_photo_access(): void
    {
        Storage::fake(config('filesystems.default'));
        Storage::disk()->buildTemporaryUrlsUsing(fn ($path) => "https://stockage.test/{$path}?signature=x");

        $photo = Media::factory()->photo()->create(['user_id' => $this->alice->id]);
        $note = VoiceNote::create([
            'media_id' => $photo->id, 'user_id' => $this->alice->id,
            'file_path' => 'voice-notes/souvenir.webm', 'mime_type' => 'audio/webm', 'size' => 10,
        ]);

        $this->actingAs($this->alice)->get("/voice-notes/{$note->id}/file")
            ->assertRedirect('https://stockage.test/voice-notes/souvenir.webm?signature=x')
            ->assertHeader('Cache-Control', 'max-age=300, private');

        // Une autre famille n'écoute pas un souvenir raconté sur une photo privée.
        $this->actingAs($this->carol)->get("/voice-notes/{$note->id}/file")->assertForbidden();
    }

    // --- Ouvrir un album : pas d'annuaire de tous les comptes ---------------

    public function test_album_access_candidates_only_list_people_sharing_a_circle(): void
    {
        $album = Album::factory()->create(['user_id' => $this->alice->id]);
        $url = "/albums/{$album->id}/access/candidates";

        $this->actingAs($this->alice)->getJson("{$url}?q=Bo")
            ->assertOk()
            ->assertExactJson([['id' => $this->bob->id, 'name' => 'Bob Martin', 'email' => null]]);

        // Quelqu'un d'une autre famille n'apparaît pas, même cherché par son nom.
        $this->actingAs($this->alice)->getJson("{$url}?q=Carole")->assertOk()->assertExactJson([]);
        // Une lettre ne suffit pas (pas d'énumération des comptes).
        $this->actingAs($this->alice)->getJson("{$url}?q=B")->assertOk()->assertExactJson([]);
        // Seul qui peut ouvrir l'album peut chercher à qui l'ouvrir.
        $this->actingAs($this->bob)->getJson("{$url}?q=Al")->assertForbidden();
    }

    public function test_album_access_candidates_show_emails_to_admins_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $album = Album::factory()->create(['user_id' => $admin->id]);

        $this->actingAs($admin)->getJson("/albums/{$album->id}/access/candidates?q=Carole")
            ->assertOk()
            ->assertJsonPath('0.id', $this->carol->id)
            ->assertJsonPath('0.email', $this->carol->email);
    }

    // --- « C'est moi » : se lier à une fiche ---------------------------------

    public function test_set_self_links_and_unlinks_own_fiche(): void
    {
        $fiche = Person::factory()->create(['user_id' => $this->alice->id]);

        $this->actingAs($this->alice)->postJson("/people/{$fiche->id}/set-self")
            ->assertOk()->assertJson(['person_id' => $fiche->id]);
        $this->assertSame($fiche->id, $this->alice->fresh()->person_id);

        // Deuxième clic : on se délie.
        $this->actingAs($this->alice->fresh())->postJson("/people/{$fiche->id}/set-self")
            ->assertOk()->assertJson(['person_id' => null]);
        $this->assertNull($this->alice->fresh()->person_id);
    }

    public function test_set_self_refuses_someone_elses_fiche_or_a_fiche_already_claimed(): void
    {
        // Fiche créée par Alice : Bob ne peut pas s'y identifier.
        $aliceFiche = Person::factory()->create(['user_id' => $this->alice->id]);
        $this->actingAs($this->bob)->postJson("/people/{$aliceFiche->id}/set-self")->assertForbidden();
        $this->assertNull($this->bob->fresh()->person_id);

        // Fiche déjà liée au compte de Bob : même sa créatrice ne la prend pas.
        $bobFiche = Person::factory()->create(['user_id' => $this->alice->id]);
        $this->bob->forceFill(['person_id' => $bobFiche->id])->save();
        $this->actingAs($this->alice)->postJson("/people/{$bobFiche->id}/set-self")->assertForbidden();
        $this->assertSame($bobFiche->id, $this->bob->fresh()->person_id);
        $this->assertNull($this->alice->fresh()->person_id);
    }

    // --- TV : « Ce jour-là » et « Délier » ------------------------------------

    private function linkedTv(): TvDevice
    {
        return TvDevice::create([
            'user_id' => $this->alice->id,
            'household_id' => $this->family->id,
            'token_hash' => TvDevice::hashToken('jeton-tv'),
            'confirmed_at' => now(),
        ]);
    }

    public function test_tv_on_this_day_shows_only_photos_from_this_day_in_past_years(): void
    {
        $this->alice->forceFill(['current_household_id' => $this->family->id])->save();
        $sameDay = Media::factory()->photo()->create(['user_id' => $this->alice->id, 'taken_at' => now()->subYears(3)->setTime(10, 0)]);
        Media::factory()->photo()->create(['user_id' => $this->alice->id, 'taken_at' => now()->subYears(3)->subDays(10)]);
        $device = $this->linkedTv();

        $this->actingAs($this->alice)->withSession(['tv_device_id' => $device->id])
            ->get('/tv/ecran/ce-jour-la')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Tv/Collection', false)
                ->has('items', 1)
                ->where('items.0.id', $sameDay->id));
    }

    public function test_tv_unlink_forgets_the_device_and_signs_out(): void
    {
        $device = $this->linkedTv();

        $this->actingAs($this->alice)->withSession(['tv_device_id' => $device->id])
            ->post('/tv/ecran/delier')
            ->assertRedirect(route('tv.screen'));

        $this->assertModelMissing($device);
        $this->assertGuest();
    }

    public function test_tv_unlink_from_a_phone_session_touches_nothing(): void
    {
        $device = $this->linkedTv();

        $this->actingAs($this->alice)->post('/tv/ecran/delier')->assertRedirect(route('dashboard'));

        $this->assertModelExists($device);
        $this->assertAuthenticatedAs($this->alice);
    }
}
