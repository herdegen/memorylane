<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Import d'un dossier biographique (manifeste JSON → fiche personne).
 */
class ImportPersonDossierTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;
    private Person $person;
    private Person $spouse;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));
        Queue::fake(); // pas de conversions/vision : on teste l'intégration, pas le traitement

        $user = User::factory()->create(['role' => 'admin']);
        $this->person = Person::factory()->create([
            'user_id' => $user->id,
            'first_name' => 'Alex',
            'last_name' => 'Durand',
            'notes' => 'Note existante',
        ]);
        $this->spouse = Person::factory()->create(['user_id' => $user->id]);
        PersonRelationship::create([
            'person1_id' => $this->person->id,
            'person2_id' => $this->spouse->id,
            'type' => 'spouse',
        ]);

        $this->dir = sys_get_temp_dir().'/dossier-'.uniqid();
        mkdir($this->dir.'/images', 0777, true);
        $img = imagecreatetruecolor(40, 30);
        imagejpeg($img, $this->dir.'/images/a.jpg');
        imagedestroy($img);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/images/a.jpg');
        @unlink($this->dir.'/manifest.json');
        @rmdir($this->dir.'/images');
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function manifest(array $override = []): string
    {
        $manifest = array_replace_recursive([
            'person' => $this->person->id,
            'person_fill' => ['notes' => 'Ne doit pas écraser', 'death_place' => 'Saint-Aubin'],
            'person_overwrite' => ['first_name' => 'Alexandre'],
            'relationships' => [[
                'person1' => $this->person->id, 'person2' => $this->spouse->id, 'type' => 'spouse',
                'start_date' => '1926-05-04', 'start_place' => 'Saint-Aubin',
            ]],
            'albums' => [['key' => 'doc', 'name' => 'Documents', 'cover' => 'a']],
            'media' => [[
                'key' => 'a', 'file' => 'a.jpg', 'title' => 'Diplôme', 'description' => 'Scan',
                'album' => 'doc', 'people' => [$this->person->id], 'tags' => ['Document'],
            ]],
            'events' => [[
                'type' => 'distinction', 'title' => 'Légion d\'honneur', 'event_date' => '1918-06-13',
                'date_precision' => 'year', 'album' => 'doc', 'media' => 'a',
            ]],
        ], $override);
        file_put_contents($this->dir.'/manifest.json', json_encode($manifest));

        return $this->dir.'/manifest.json';
    }

    public function test_import_remplit_la_fiche_sans_ecraser_et_cree_les_briques_generiques(): void
    {
        $this->artisan('person:import-dossier', ['manifest' => $this->manifest()])->assertSuccessful();

        $person = $this->person->fresh();
        $this->assertSame('Note existante', $person->notes, 'un champ déjà renseigné n\'est pas écrasé');
        $this->assertSame('Saint-Aubin', $person->death_place, 'un champ vide est rempli');
        $this->assertSame('Alexandre', $person->first_name, 'overwrite explicite');
        $this->assertSame('Alexandre Durand', $person->name);

        $album = Album::where('name', 'Documents')->firstOrFail();
        $media = Media::firstOrFail();
        $this->assertSame('Diplôme', $media->title);
        $this->assertTrue($album->media->contains($media));
        $this->assertSame($media->id, $album->cover_media_id);
        $this->assertTrue($person->media->contains($media));
        $this->assertTrue($media->tags->contains('name', 'Document'));

        $event = LifeEvent::firstOrFail();
        $this->assertSame('distinction', $event->type);
        $this->assertSame('year', $event->date_precision);
        $this->assertSame($media->id, $event->media_id);
        $this->assertSame($album->id, $event->album_id);

        $rel = PersonRelationship::firstOrFail();
        $this->assertSame('1926-05-04', $rel->start_date->format('Y-m-d'));
    }

    public function test_import_rejoue_ne_duplique_rien(): void
    {
        $path = $this->manifest();
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();

        $this->assertSame(1, Media::count());
        $this->assertSame(1, Album::count());
        $this->assertSame(1, LifeEvent::count());
        $this->assertSame(1, Tag::count());
        $this->assertSame(1, PersonRelationship::count());
    }

    public function test_sync_events_met_a_jour_et_elague_les_moments(): void
    {
        $this->artisan('person:import-dossier', ['manifest' => $this->manifest()])->assertSuccessful();

        // Moment ajouté à la main, hors manifeste.
        LifeEvent::create([
            'person_id' => $this->person->id, 'user_id' => $this->person->user_id,
            'type' => 'moment', 'title' => 'Hors manifeste', 'event_date' => '1930-01-01',
        ]);

        // Même moment (titre + date), enrichi d'une position et d'un nouvel album.
        $path = $this->manifest([
            'albums' => [['key' => 'doc', 'name' => 'Documents'], ['key' => 'lh', 'name' => 'Légion d\'honneur']],
            'events' => [['latitude' => 40.05, 'longitude' => 26.19, 'place' => 'Dardanelles', 'album' => 'lh']],
        ]);

        // Sans --sync-events : les champs vides sont remplis, rien n'est élagué.
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();
        $this->assertEqualsWithDelta(40.05, LifeEvent::where('title', 'Légion d\'honneur')->first()->latitude, 0.0001);
        $this->assertSame(2, LifeEvent::count());

        $this->artisan('person:import-dossier', ['manifest' => $path, '--sync-events' => true])->assertSuccessful();
        $event = LifeEvent::where('title', 'Légion d\'honneur')->firstOrFail();
        $this->assertEqualsWithDelta(40.05, $event->latitude, 0.0001);
        $this->assertSame('Dardanelles', $event->place);
        $this->assertSame(Album::where('name', 'Légion d\'honneur')->first()->id, $event->album_id);
        $this->assertSame(1, LifeEvent::count(), 'le moment hors manifeste est supprimé');

        // Média reclassé dans un autre album du dossier : il quitte l'ancien.
        $path = $this->manifest([
            'albums' => [['key' => 'doc', 'name' => 'Documents'], ['key' => 'lh', 'name' => 'Légion d\'honneur']],
            'media' => [['album' => 'lh']],
        ]);
        $this->artisan('person:import-dossier', ['manifest' => $path, '--sync-events' => true])->assertSuccessful();
        $media = Media::firstOrFail();
        $this->assertFalse(Album::where('name', 'Documents')->first()->media->contains($media));
        $this->assertTrue(Album::where('name', 'Légion d\'honneur')->first()->media->contains($media));
    }

    public function test_remove_albums_retire_l_album_et_garde_les_medias(): void
    {
        $this->artisan('person:import-dossier', ['manifest' => $this->manifest()])->assertSuccessful();

        $path = $this->manifest(['remove_albums' => ['Documents'], 'albums' => [['key' => 'doc', 'name' => 'Pièces officielles']]]);
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();

        $this->assertNull(Album::where('name', 'Documents')->first());
        $this->assertNotNull(Album::where('name', 'Pièces officielles')->first());
        $this->assertSame(1, Media::count());
    }

    public function test_replaces_supprime_l_ancienne_version_du_fichier(): void
    {
        $this->artisan('person:import-dossier', ['manifest' => $this->manifest()])->assertSuccessful();
        $old = Media::firstOrFail();
        $oldHash = $old->content_hash;

        // Version retouchée : autre contenu, même rôle dans le manifeste.
        $img = imagecreatetruecolor(50, 40);
        imagefilledrectangle($img, 0, 0, 49, 39, imagecolorallocate($img, 200, 200, 200));
        imagejpeg($img, $this->dir.'/images/a.jpg');
        imagedestroy($img);

        $path = $this->manifest(['media' => [['replaces' => $oldHash]]]);
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();

        $this->assertSame(1, Media::withTrashed()->count(), 'l\'ancienne version est supprimée définitivement');
        $new = Media::firstOrFail();
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame($new->id, LifeEvent::firstOrFail()->media_id);
        $this->assertTrue(Album::firstOrFail()->media->contains($new));

        // Rejeu : la nouvelle version est reconnue, rien ne bouge.
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();
        $this->assertSame(1, Media::withTrashed()->count());
    }

    public function test_un_visage_pose_a_la_main_donne_un_avatar_cadre(): void
    {
        $path = $this->manifest(['faces' => [['media' => 'a', 'person' => $this->person->id, 'box' => ['x' => 35, 'y' => 9, 'width' => 24, 'height' => 18]]]]);
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();

        $face = \App\Models\DetectedFace::where('person_id', $this->person->id)->get();
        $this->assertCount(1, $face);
        $this->assertSame('matched', $face[0]->status);
        $this->assertSame(24, (int) $face[0]->bounding_box['width']);

        $data = $this->actingAs($this->person->user)->getJson("/people/{$this->person->id}")->assertOk()->json();
        $this->assertStringContainsString('/face-avatar', $data['person']['avatar_url'] ?? '');
    }

    public function test_galerie_du_moment_rattachee_sans_album(): void
    {
        $img = imagecreatetruecolor(30, 30);
        imagejpeg($img, $this->dir.'/images/b.jpg');
        imagedestroy($img);

        $path = $this->manifest([
            'albums' => [],
            'media' => [
                ['key' => 'a', 'file' => 'a.jpg', 'title' => 'Diplôme', 'album' => null, 'people' => [$this->person->id], 'tags' => []],
                ['key' => 'b', 'file' => 'b.jpg', 'title' => 'Remise', 'people' => [$this->person->id], 'tags' => []],
            ],
            'events' => [['album' => null, 'photos' => ['b', 'a']]],
        ]);
        $this->artisan('person:import-dossier', ['manifest' => $path])->assertSuccessful();

        $event = LifeEvent::firstOrFail();
        $this->assertSame(['Remise', 'Diplôme'], $event->photos->pluck('title')->all(), 'ordre du manifeste');
        $this->assertNull($event->album_id);

        $timeline = collect($this->actingAs($this->person->user)->getJson("/people/{$this->person->id}/timeline")->json());
        $this->assertSame(['Remise', 'Diplôme'], array_column($timeline->firstWhere('kind', 'distinction')['photos'], 'title'));

        @unlink($this->dir.'/images/b.jpg');
    }

    public function test_dry_run_n_ecrit_rien(): void
    {
        $this->artisan('person:import-dossier', ['manifest' => $this->manifest(), '--dry-run' => true])->assertSuccessful();

        $this->assertSame(0, Media::count());
        $this->assertSame(0, LifeEvent::count());
        $this->assertSame('Alex', $this->person->fresh()->first_name);
    }

    public function test_manifeste_invalide_est_refuse_avant_toute_ecriture(): void
    {
        $path = $this->manifest(['events' => [['type' => 'inconnu']]]);

        $this->artisan('person:import-dossier', ['manifest' => $path])->assertFailed();

        $this->assertSame(0, Media::count());
        $this->assertSame(0, LifeEvent::count());
    }
}
