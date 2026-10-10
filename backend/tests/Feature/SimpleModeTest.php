<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Models\VoiceNote;
use App\Notifications\Push\VoiceNoteRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Mode grand-parent : lien tablette sans mot de passe, interface /simple
 * cloisonnée, famille vue depuis la fiche du grand-parent, mémos vocaux.
 */
class SimpleModeTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Person $grandma;
    private Person $son;
    private Person $grandson;
    private Media $photo;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));

        $this->owner = User::factory()->create();
        $this->grandma = Person::factory()->create(['user_id' => $this->owner->id, 'first_name' => 'Jeanne', 'gender' => 'F', 'birth_date' => '1935-04-02']);
        $this->son = Person::factory()->create(['user_id' => $this->owner->id, 'first_name' => 'Paul', 'gender' => 'M', 'mother_id' => $this->grandma->id, 'birth_date' => '1960-05-01']);
        $this->grandson = Person::factory()->create(['user_id' => $this->owner->id, 'first_name' => 'Léo', 'gender' => 'M', 'father_id' => $this->son->id, 'birth_date' => '1990-05-01']);

        // Photo de Paul dans un album où apparaît Jeanne → visible par Jeanne.
        $this->photo = Media::factory()->create(['user_id' => $this->owner->id, 'type' => 'photo', 'title' => 'IMG_1234.jpg', 'taken_at' => '1978-07-14 12:00:00']);
        $this->photo->people()->attach([$this->grandma->id, $this->son->id]);
        $album = Album::factory()->create(['user_id' => $this->owner->id, 'name' => 'Vacances à la plage']);
        $album->media()->attach($this->photo->id, ['order' => 0]);
    }

    private function createTabletLink(): string
    {
        $this->artisan('tablet:create', ['person' => $this->grandma->id])->assertSuccessful();

        return User::where('person_id', $this->grandma->id)->firstOrFail()->id;
    }

    private function grandmaUser(): User
    {
        $this->createTabletLink();

        return User::where('person_id', $this->grandma->id)->firstOrFail();
    }

    public function test_le_lien_tablette_connecte_durablement_et_ouvre_l_accueil_simplifie(): void
    {
        $this->artisan('tablet:create', ['person' => $this->grandma->id])
            ->expectsOutputToContain('/tablette/')
            ->assertSuccessful();

        $user = User::where('person_id', $this->grandma->id)->firstOrFail();
        $this->assertTrue($user->simple_mode);
        $this->assertNotNull($user->tablet_token_hash);

        // On rejoue la génération pour connaître le jeton en clair.
        $token = str_repeat('a', 48);
        $user->forceFill(['tablet_token_hash' => hash('sha256', $token)])->save();

        $response = $this->get("/tablette/{$token}");
        $response->assertRedirect(route('simple.home'));
        $this->assertAuthenticatedAs($user);
        $this->assertNotEmpty(collect($response->headers->getCookies())->filter(fn ($c) => str_starts_with($c->getName(), 'remember_web_')));
    }

    public function test_un_lien_regenere_ou_revoque_ne_fonctionne_plus(): void
    {
        $user = $this->grandmaUser();
        $token = str_repeat('b', 48);
        $user->forceFill(['tablet_token_hash' => hash('sha256', $token)])->save();

        $this->artisan('tablet:create', ['person' => $this->grandma->id, '--revoke' => true])->assertSuccessful();
        $this->assertNull($user->fresh()->tablet_token_hash);

        $this->get("/tablette/{$token}")->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_un_compte_existant_relie_a_la_fiche_est_reutilise(): void
    {
        $existing = User::factory()->create(['person_id' => $this->grandma->id]);

        $this->artisan('tablet:create', ['person' => $this->grandma->id])->assertSuccessful();

        $this->assertSame(1, User::where('person_id', $this->grandma->id)->count());
        $this->assertTrue($existing->fresh()->simple_mode);
    }

    public function test_le_mode_simplifie_est_cloisonne(): void
    {
        $user = $this->grandmaUser();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('simple.home'));
        $this->actingAs($user)->get('/people')->assertRedirect(route('simple.home'));
        $this->actingAs($user)->putJson("/media/{$this->photo->id}", ['title' => 'x'])->assertForbidden();
        $this->actingAs($user)->get(route('media.file', $this->photo))->assertRedirect();
    }

    public function test_accueil_photos_et_famille(): void
    {
        $user = $this->grandmaUser();

        $this->actingAs($user)->get('/simple')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Simple/Home', false)
                ->where('firstName', 'Jeanne')
                ->where('memory.id', $this->photo->id));

        // Légende : nom de fichier remplacé par l'album, « vous » pour soi.
        $this->actingAs($user)->get('/simple/photos?start=' . $this->photo->id)
            ->assertInertia(fn ($page) => $page
                ->component('Simple/Photos', false)
                ->where('photos.0.title', 'Vacances à la plage')
                ->where('photos.0.when', 'juillet 1978')
                ->where('photos.0.who', ['vous', 'Paul']));

        $this->actingAs($user)->get('/simple/famille')
            ->assertInertia(fn ($page) => $page
                ->component('Simple/Family', false)
                ->where('members.0.name', 'Paul')
                ->where('members.0.relation', 'votre fils')
                ->where('members.1.name', 'Léo')
                ->where('members.1.relation', 'votre petit-fils'));
    }

    public function test_raconter_une_photo_enregistre_le_memo_et_previent_la_famille(): void
    {
        Notification::fake();
        $user = $this->grandmaUser();
        $paulAccount = User::factory()->create(['person_id' => $this->son->id]);
        foreach ([$this->owner, $paulAccount] as $u) {
            $u->updatePushSubscription('https://push.example.org/' . $u->id, 'k', 't');
            $u->setPreference('push', ['social' => true, 'birthdays' => true, 'digest' => true]);
        }

        $audio = new UploadedFile(base_path('tests/fixtures/voice-note.webm'), 'souvenir.webm', null, null, true);

        $this->actingAs($user)
            ->post("/media/{$this->photo->id}/voice-notes", ['audio' => $audio, 'duration' => 42], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('voice_note.narrator', 'Jeanne');

        $note = VoiceNote::firstOrFail();
        $this->assertSame(42, $note->duration_seconds);
        $this->assertSame($this->grandma->id, $note->person_id);
        $this->assertStringStartsWith('audio/', $note->mime_type);
        Storage::assertExists($note->file_path);

        Notification::assertSentTo($this->owner, VoiceNoteRecorded::class);
        Notification::assertSentTo($paulAccount, VoiceNoteRecorded::class);
        Notification::assertNotSentTo($user, VoiceNoteRecorded::class);

        // La famille l'entend sur la fiche média.
        $this->actingAs($this->owner)->get("/media/{$this->photo->id}")
            ->assertInertia(fn ($page) => $page
                ->where('voiceNotes.0.narrator', 'Jeanne')
                ->where('voiceNotes.0.can_delete', true));
    }

    public function test_pas_de_memo_sur_une_photo_invisible_ni_de_suppression_par_un_tiers(): void
    {
        $user = $this->grandmaUser();
        $private = Media::factory()->create(['type' => 'photo']);
        $audio = new UploadedFile(base_path('tests/fixtures/voice-note.webm'), 'souvenir.webm', null, null, true);

        $this->actingAs($user)
            ->post("/media/{$private->id}/voice-notes", ['audio' => $audio], ['Accept' => 'application/json'])
            ->assertForbidden();

        $note = VoiceNote::create([
            'media_id' => $this->photo->id, 'user_id' => $this->owner->id,
            'file_path' => 'voice-notes/x.webm', 'mime_type' => 'audio/webm', 'size' => 10,
        ]);
        $stranger = User::factory()->create(['person_id' => $this->son->id]);

        $this->actingAs($stranger)->deleteJson("/voice-notes/{$note->id}")->assertForbidden();
        $this->actingAs($this->owner)->deleteJson("/voice-notes/{$note->id}")->assertOk();
        $this->assertDatabaseMissing('voice_notes', ['id' => $note->id]);
    }
}
