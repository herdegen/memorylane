<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Models\VoiceNote;
use App\Services\DepartureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Départs : quitter un cercle (le compte reste), quitter l'application
 * (programmé, puis transferts / retraits selon les choix).
 */
class DepartureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->admin = User::factory()->admin()->create(['created_at' => now()->subYear()]);
    }

    private function sharedPhoto(User $owner, Household $circle): Media
    {
        $m = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $m->households()->attach($circle->id, ['added_by' => $owner->id]);

        return $m;
    }

    // ------------------------------------------------------------------ cercle

    public function test_quitter_un_groupe_en_retirant_ses_photos(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $circle = Household::factory()->friends()->create(['created_by' => $other->id]);
        $circle->members()->attach([$me->id, $other->id]);
        $photo = $this->sharedPhoto($me, $circle);
        $theirs = $this->sharedPhoto($other, $circle);
        $album = Album::factory()->create(['user_id' => $me->id]);
        $album->households()->attach($circle->id);

        $summary = $this->actingAs($me)->getJson("/households/{$circle->id}/leave")->assertOk()->json();
        $this->assertSame(1, $summary['shared_photos']);
        $this->assertSame(1, $summary['shared_albums']);
        $this->assertFalse($summary['needs_successor']);

        $this->actingAs($me)->postJson("/households/{$circle->id}/leave", ['photos' => 'withdraw'])->assertOk();

        $this->assertFalse($circle->isMember($me->fresh()));
        $this->assertFalse(DB::table('household_media')->where('media_id', $photo->id)->exists(), 'ma photo retirée du groupe');
        $this->assertTrue(DB::table('household_media')->where('media_id', $theirs->id)->exists(), 'pas celles des autres');
        $this->assertFalse($album->households()->exists());
        $this->assertDatabaseHas('media', ['id' => $photo->id, 'user_id' => $me->id, 'deleted_at' => null]);
    }

    public function test_le_createur_d_un_groupe_designe_un_successeur(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $circle = Household::factory()->friends()->create(['created_by' => $me->id]);
        $circle->members()->attach([$me->id, $other->id]);
        $photo = $this->sharedPhoto($me, $circle);

        $this->actingAs($me)->postJson("/households/{$circle->id}/leave", ['photos' => 'keep'])->assertStatus(422);

        $this->actingAs($me)->postJson("/households/{$circle->id}/leave", ['photos' => 'keep', 'successor_id' => $other->id])->assertOk();
        $this->assertSame($other->id, $circle->fresh()->created_by);
        $this->assertTrue(DB::table('household_media')->where('media_id', $photo->id)->exists(), 'photos laissées au groupe');
    }

    public function test_le_dernier_membre_ferme_le_groupe(): void
    {
        $me = User::factory()->create();
        $circle = Household::factory()->friends()->create(['created_by' => $me->id]);
        $circle->members()->attach($me->id);

        $this->actingAs($me)->postJson("/households/{$circle->id}/leave", ['photos' => 'keep'])->assertOk();
        $this->assertDatabaseMissing('households', ['id' => $circle->id]);
    }

    public function test_quitter_la_famille_garde_la_fiche_sans_adresse(): void
    {
        $family = Household::factory()->create(['created_by' => $this->admin->id]);
        $me = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $this->admin->id, 'in_family_directory' => true]);
        $person->forceFill(['address' => '1 rue X', 'address_latitude' => 48.1, 'address_longitude' => 2.1])->saveQuietly();
        $me->update(['person_id' => $person->id]);
        $family->members()->attach([$me->id, $this->admin->id]);

        $this->actingAs($me)->postJson("/households/{$family->id}/leave", ['photos' => 'keep'])->assertOk();

        $this->assertDatabaseHas('people', ['id' => $person->id, 'deleted_at' => null, 'address' => null]);
    }

    // ------------------------------------------------------------ application

    public function test_demande_de_depart_programmee_puis_annulee(): void
    {
        $me = User::factory()->create(['name' => 'Léa Martin']);

        $this->actingAs($me)->post('/account/departure', ['photos' => 'keep_shared', 'voice_notes' => 'keep', 'untag_me' => false, 'confirm' => 'paul'])
            ->assertSessionHasErrors('confirm');
        $this->assertNull($me->fresh()->departure_requested_at);

        $this->actingAs($me)->post('/account/departure', ['photos' => 'keep_shared', 'voice_notes' => 'keep', 'untag_me' => false, 'confirm' => 'LÉA'])
            ->assertRedirect('/account/departure');
        $this->assertNotNull($me->fresh()->departure_requested_at);

        $this->actingAs($me->fresh())->delete('/account/departure')->assertRedirect('/account/departure');
        $this->assertNull($me->fresh()->departure_requested_at);
    }

    public function test_le_dernier_admin_ne_peut_pas_partir(): void
    {
        $this->actingAs($this->admin)->post('/account/departure', ['photos' => 'keep_shared', 'voice_notes' => 'keep', 'untag_me' => false, 'confirm' => explode(' ', $this->admin->name)[0]])
            ->assertSessionHasErrors('account');
    }

    public function test_depart_par_defaut_garde_le_partage_retire_le_prive(): void
    {
        $family = Household::factory()->create(['created_by' => $this->admin->id]);
        $me = User::factory()->create();
        $family->members()->attach([$me->id, $this->admin->id]);
        $shared = $this->sharedPhoto($me, $family);
        $private = Media::factory()->photo()->create(['user_id' => $me->id]);
        $sharedAlbum = Album::factory()->create(['user_id' => $me->id]);
        $sharedAlbum->households()->attach($family->id);
        $privateAlbum = Album::factory()->create(['user_id' => $me->id]);
        $privateAlbum->media()->attach($private->id);
        $fiche = Person::factory()->create(['user_id' => $me->id]);
        $moment = LifeEvent::create(['person_id' => $fiche->id, 'user_id' => $me->id, 'type' => 'moment', 'title' => 'X', 'event_date' => '2020-01-01']);
        $note = VoiceNote::create(['media_id' => $shared->id, 'user_id' => $me->id, 'file_path' => 'voice/a.webm', 'mime_type' => 'audio/webm', 'size' => 10]);
        $friends = Household::factory()->friends()->create(['created_by' => $me->id]);
        $friends->members()->attach([$me->id, $this->admin->id]);

        $me->forceFill(['departure_requested_at' => now()->subDays(31), 'departure_options' => ['photos' => 'keep_shared', 'voice_notes' => 'keep', 'untag_me' => false]])->save();
        $this->artisan('memorylane:process-departures')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $me->id]);
        $this->assertSame($this->admin->id, $shared->fresh()->user_id, 'photo partagée → admin');
        $this->assertNull(Media::withTrashed()->find($private->id), 'photo privée retirée');
        $this->assertSame($this->admin->id, $sharedAlbum->fresh()->user_id);
        $this->assertNull(Album::withTrashed()->find($privateAlbum->id));
        $this->assertSame($this->admin->id, $fiche->fresh()->user_id, 'la fiche reste dans l\'arbre');
        $this->assertSame($this->admin->id, $moment->fresh()->user_id);
        $this->assertNotNull($note->fresh(), 'souvenir raconté gardé');
        $this->assertSame($this->admin->id, $friends->fresh()->created_by, 'groupe repris par un membre');
    }

    public function test_depart_tout_retirer_et_son_nom_des_photos(): void
    {
        $family = Household::factory()->create(['created_by' => $this->admin->id]);
        $me = User::factory()->create();
        $mine = Person::factory()->create(['user_id' => $this->admin->id]);
        $me->update(['person_id' => $mine->id]);
        $family->members()->attach([$me->id, $this->admin->id]);
        $shared = $this->sharedPhoto($me, $family);
        $theirPhoto = Media::factory()->photo()->create(['user_id' => $this->admin->id]);
        $theirPhoto->people()->attach($mine->id);

        $me->forceFill(['departure_requested_at' => now()->subDays(31), 'departure_options' => ['photos' => 'remove_all', 'voice_notes' => 'remove', 'untag_me' => true]])->save();
        app(DepartureService::class)->finalizeAccountDeparture($me->fresh());

        $this->assertNull(Media::withTrashed()->find($shared->id), 'tout retiré');
        $this->assertNotNull($theirPhoto->fresh(), 'les photos des autres restent');
        $this->assertFalse($theirPhoto->people()->where('people.id', $mine->id)->exists(), 'son nom retiré');
        $this->assertNotNull($mine->fresh(), 'sa fiche reste');
    }

    // ------------------------------------------------------- retraits (admin)

    public function test_le_responsable_retire_un_membre_et_ses_photos(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $circle = Household::factory()->friends()->create(['created_by' => $owner->id, 'name' => 'Les copains']);
        $circle->members()->attach([$owner->id, $member->id]);
        $photo = $this->sharedPhoto($member, $circle);

        // Un simple membre ne retire personne.
        $this->actingAs($member)->deleteJson("/households/{$circle->id}/members/{$owner->id}", ['photos' => 'keep'])->assertForbidden();

        $this->assertSame(1, $this->actingAs($owner)->getJson("/households/{$circle->id}/members/{$member->id}/removal")->assertOk()->json('shared_photos'));
        $this->actingAs($owner)->deleteJson("/households/{$circle->id}/members/{$member->id}", ['photos' => 'withdraw'])->assertOk();

        $this->assertFalse($circle->isMember($member->fresh()));
        $this->assertFalse(DB::table('household_media')->where('media_id', $photo->id)->exists());
        Notification::assertSentTo($member, \App\Notifications\RemovedFromCircle::class, fn ($n) => $n->circleName === 'Les copains' && $n->photosWithdrawn);
    }

    public function test_l_admin_retire_un_compte_depuis_l_administration(): void
    {
        Notification::fake();
        $member = User::factory()->create(['name' => 'Paul Test']);
        $private = Media::factory()->photo()->create(['user_id' => $member->id]);
        $fiche = Person::factory()->create(['user_id' => $member->id]);

        $this->actingAs($this->admin);
        \Livewire\Livewire::test(\App\Filament\Resources\UserResource\Pages\ListUsers::class)
            ->callTableAction('removeAccount', $member, ['photos' => 'keep_shared', 'voice_notes' => 'keep', 'untag_me' => false, 'confirm' => 'paul'])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('users', ['id' => $member->id]);
        $this->assertNull(Media::withTrashed()->find($private->id));
        $this->assertSame($this->admin->id, $fiche->fresh()->user_id);
        Notification::assertSentTo($member, \App\Notifications\AccountRemovedByAdmin::class);
    }

    // ------------------------------------------------------------------ export

    public function test_export_des_photos_en_archive_avec_index(): void
    {
        Notification::fake();
        $disk = Storage::disk(config('filesystems.default'));
        $me = User::factory()->create();
        $a = Media::factory()->photo()->create(['user_id' => $me->id, 'original_name' => 'plage.jpg', 'taken_at' => '2020-07-14 10:00:00']);
        $b = Media::factory()->photo()->create(['user_id' => $me->id, 'original_name' => 'plage.jpg', 'taken_at' => '2020-07-14 10:00:00']);
        $c = Media::factory()->photo()->create(['user_id' => $me->id, 'original_name' => 'vieux.jpg', 'taken_at' => null]);
        foreach ([$a, $b, $c] as $m) {
            $disk->put($m->file_path, 'jpeg-'.$m->id);
        }
        $album = Album::factory()->create(['user_id' => $me->id, 'name' => 'Vacances']);
        $album->media()->attach($a->id);
        Media::factory()->photo()->create(); // celle d'un autre : pas exportée

        // Le ménage différé (8 jours) ne doit pas tourner tout de suite ici.
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\DeleteExportFiles::class]);
        (new \App\Jobs\ExportUserMedia($me))->handle(app(\App\Services\S3Service::class));
        \Illuminate\Support\Facades\Bus::assertDispatched(\App\Jobs\DeleteExportFiles::class);

        $zips = collect($disk->allFiles('exports'))->filter(fn ($f) => str_ends_with($f, '.zip'))->values();
        $this->assertCount(1, $zips);
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        file_put_contents($tmp, $disk->get($zips[0]));
        $zip = new \ZipArchive();
        $zip->open($tmp);
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->sort()->values()->all();
        $this->assertSame(['2020/2020-07-14_plage (2).jpg', '2020/2020-07-14_plage.jpg', 'index.csv', 'sans-date/vieux.jpg'], $names);
        $this->assertStringContainsString('Vacances', $zip->getFromName('index.csv'));
        $zip->close();
        @unlink($tmp);

        Notification::assertSentTo($me, \App\Notifications\MediaExportReady::class, fn ($n) => $n->count === 3 && count($n->parts) === 1);
    }

    public function test_une_demande_d_export_toutes_les_6_heures(): void
    {
        Queue::fake();
        $me = User::factory()->create();

        $this->actingAs($me)->postJson('/account/export')->assertOk();
        $this->actingAs($me)->postJson('/account/export')->assertStatus(429);
        Queue::assertPushed(\App\Jobs\ExportUserMedia::class, 1);
    }

    public function test_un_depart_recent_n_est_pas_encore_applique(): void
    {
        $me = User::factory()->create();
        $me->forceFill(['departure_requested_at' => now()->subDays(5), 'departure_options' => ['photos' => 'remove_all']])->save();
        $this->artisan('memorylane:process-departures')->assertSuccessful();
        $this->assertDatabaseHas('users', ['id' => $me->id]);
    }
}
