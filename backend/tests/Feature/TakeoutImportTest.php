<?php

namespace Tests\Feature;

use App\Jobs\ImportTakeoutArchive;
use App\Models\Media;
use App\Models\MediaMetadata;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TakeoutImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('public');
        Bus::fake([\App\Jobs\ProcessUploadedMedia::class, \App\Jobs\GenerateMediaConversions::class, \App\Jobs\AnalyzeMediaWithVision::class]);
    }

    /**
     * Fabrique une archive Takeout minimale : une photo + son JSON compagnon.
     */
    protected function makeTakeoutZip(array $entries): string
    {
        $path = sys_get_temp_dir() . '/takeout_test_' . uniqid() . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    protected function jpegBytes(): string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
    }

    protected function sidecar(array $overrides = []): string
    {
        return json_encode(array_merge([
            'title' => 'plage.jpg',
            'description' => 'La plage de Bretagne',
            'photoTakenTime' => ['timestamp' => '1629559703'],
            'geoData' => ['latitude' => 48.3903, 'longitude' => -4.4863, 'altitude' => 12.0],
        ], $overrides));
    }

    /**
     * Une photo avec JSON compagnon est importée avec géoloc, date et description.
     */
    public function test_photo_is_imported_with_geolocation_from_sidecar(): void
    {
        $zipPath = $this->makeTakeoutZip([
            'Takeout/Google Photos/Photos from 2021/plage.jpg' => $this->jpegBytes(),
            'Takeout/Google Photos/Photos from 2021/plage.jpg.supplemental-metadata.json' => $this->sidecar(),
            'Takeout/Google Photos/metadata.json' => '{"ignored": true}',
        ]);

        (new ImportTakeoutArchive($this->user->id, $zipPath))->handle(app(MediaService::class));

        $media = Media::where('original_name', 'plage.jpg')->first();
        $this->assertNotNull($media);
        $this->assertEquals('2021-08-21', $media->taken_at->format('Y-m-d'));
        $this->assertEquals('La plage de Bretagne', $media->description);

        $metadata = MediaMetadata::where('media_id', $media->id)->first();
        $this->assertNotNull($metadata);
        $this->assertEqualsWithDelta(48.3903, (float) $metadata->latitude, 0.0001);
        $this->assertEqualsWithDelta(-4.4863, (float) $metadata->longitude, 0.0001);

        $this->assertFileDoesNotExist($zipPath); // archive nettoyée
    }

    /**
     * Un média déjà présent (import Picker) n'est pas dupliqué : il est
     * enrichi de sa géolocalisation et de sa date.
     */
    public function test_existing_media_is_enriched_not_duplicated(): void
    {
        $existing = Media::factory()->create([
            'user_id' => $this->user->id,
            'original_name' => 'plage.jpg',
            'taken_at' => null,
        ]);

        $zipPath = $this->makeTakeoutZip([
            'Takeout/Google Photos/Photos from 2021/plage.jpg' => $this->jpegBytes(),
            'Takeout/Google Photos/Photos from 2021/plage.jpg.supplemental-metadata.json' => $this->sidecar(),
        ]);

        (new ImportTakeoutArchive($this->user->id, $zipPath))->handle(app(MediaService::class));

        $this->assertEquals(1, Media::where('original_name', 'plage.jpg')->count());

        $metadata = MediaMetadata::where('media_id', $existing->id)->first();
        $this->assertNotNull($metadata);
        $this->assertEqualsWithDelta(48.3903, (float) $metadata->latitude, 0.0001);
        $this->assertNotNull($existing->fresh()->taken_at);
    }

    /**
     * Une géoloc déjà présente n'est jamais écrasée par le JSON.
     */
    public function test_existing_geolocation_is_never_overwritten(): void
    {
        $existing = Media::factory()->create([
            'user_id' => $this->user->id,
            'original_name' => 'plage.jpg',
        ]);
        MediaMetadata::create([
            'media_id' => $existing->id,
            'latitude' => 45.0,
            'longitude' => 5.0,
        ]);

        $zipPath = $this->makeTakeoutZip([
            'Takeout/Google Photos/Photos from 2021/plage.jpg' => $this->jpegBytes(),
            'Takeout/Google Photos/Photos from 2021/plage.jpg.supplemental-metadata.json' => $this->sidecar(),
        ]);

        (new ImportTakeoutArchive($this->user->id, $zipPath))->handle(app(MediaService::class));

        $metadata = MediaMetadata::where('media_id', $existing->id)->first();
        $this->assertEqualsWithDelta(45.0, (float) $metadata->latitude, 0.0001);
    }

    /**
     * La même photo dupliquée dans plusieurs albums du ZIP n'est importée qu'une fois.
     */
    public function test_photo_duplicated_across_albums_is_imported_once(): void
    {
        $zipPath = $this->makeTakeoutZip([
            'Takeout/Google Photos/Photos from 2021/plage.jpg' => $this->jpegBytes(),
            'Takeout/Google Photos/Album Vacances/plage.jpg' => $this->jpegBytes(),
        ]);

        (new ImportTakeoutArchive($this->user->id, $zipPath))->handle(app(MediaService::class));

        $this->assertEquals(1, Media::where('original_name', 'plage.jpg')->count());
    }

    /**
     * Les albums Google Photos de l'archive sont recréés avec leurs photos,
     * le titre venant du metadata.json du dossier.
     */
    public function test_google_albums_are_recreated_from_archive(): void
    {
        $zipPath = $this->makeTakeoutZip([
            // photo1 : dans le flux chronologique ET dans un album
            'Takeout/Google Photos/Photos from 2021/plage.jpg' => $this->jpegBytes(),
            'Takeout/Google Photos/Vacances 2019/plage.jpg' => $this->jpegBytes(),
            // photo2 : uniquement dans l'album
            'Takeout/Google Photos/Vacances 2019/dune.jpg' => $this->jpegBytes(),
            'Takeout/Google Photos/Vacances 2019/metadata.json' => json_encode(['title' => 'Vacances en Bretagne']),
        ]);

        (new ImportTakeoutArchive($this->user->id, $zipPath))->handle(app(MediaService::class));

        // Pas d'album pour le dossier chronologique
        $this->assertEquals(0, \App\Models\Album::where('name', 'like', 'Photos from%')->count());

        $album = \App\Models\Album::where('name', 'Vacances en Bretagne')->first();
        $this->assertNotNull($album);
        $this->assertEquals(2, $album->media()->count());
        $this->assertNotNull($album->cover_media_id);
        // Les deux photos existent une seule fois chacune
        $this->assertEquals(2, Media::count());
    }

    /**
     * Un album MemoryLane du même nom est réutilisé, pas dupliqué.
     */
    public function test_existing_album_is_reused(): void
    {
        $album = \App\Models\Album::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Vacances 2019',
        ]);

        $zipPath = $this->makeTakeoutZip([
            'Takeout/Google Photos/Vacances 2019/plage.jpg' => $this->jpegBytes(),
        ]);

        (new ImportTakeoutArchive($this->user->id, $zipPath))->handle(app(MediaService::class));

        $this->assertEquals(1, \App\Models\Album::where('name', 'Vacances 2019')->count());
        $this->assertEquals(1, $album->media()->count());
    }

    /**
     * L'upload d'archives dispatche un job par ZIP.
     */
    public function test_upload_dispatches_import_job(): void
    {
        Bus::fake();

        $zipPath = $this->makeTakeoutZip(['Takeout/Google Photos/x.jpg' => $this->jpegBytes()]);

        $response = $this->actingAs($this->user)->post('/takeout', [
            'archives' => [new UploadedFile($zipPath, 'takeout-001.zip', 'application/zip', null, true)],
        ]);

        $response->assertRedirect(route('media.index'));
        $response->assertSessionHas('success');
        Bus::assertDispatched(ImportTakeoutArchive::class, fn ($job) => $job->userId === $this->user->id);
    }

    /**
     * Un fichier non-ZIP est refusé.
     */
    public function test_non_zip_upload_is_rejected(): void
    {
        $response = $this->actingAs($this->user)->post('/takeout', [
            'archives' => [UploadedFile::fake()->image('photo.jpg')],
        ]);

        $response->assertSessionHasErrors();
    }
}
