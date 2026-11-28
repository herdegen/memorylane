<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\MediaConversion;
use App\Models\Person;
use App\Models\User;
use App\Services\PerceptualHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Versions retouchées : rattachées à leur photo, scan d'origine gardé, bascule. */
class AttachRetouchedTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $log;

    private ?string $logBefore;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->dir = storage_path('framework/testing/retouche-'.uniqid());
        File::makeDirectory($this->dir, 0775, true);
        $this->log = storage_path('app/attach-retouched.jsonl');
        $this->logBefore = File::exists($this->log) ? File::get($this->log) : null;
        $this->me = User::factory()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->logBefore === null ? File::delete($this->log) : File::put($this->log, $this->logBefore);
        parent::tearDown();
    }

    /** Image à motifs (dHash discriminant). */
    private function picture(int $width, int $height, int $variant): string
    {
        $img = new \Imagick;
        $img->newImage($width, $height, new \ImagickPixel('#ffffff'));
        $draw = new \ImagickDraw;
        foreach (range(0, 7) as $i) {
            $draw->setFillColor(new \ImagickPixel(($i + $variant) % 2 ? '#000000' : '#bbbbbb'));
            $x = ($i * 37 * $variant) % 100 / 100 * $width;
            $draw->rectangle($x, $i / 8 * $height, $x + $width / 5, ($i + 1) / 8 * $height);
        }
        $img->drawImage($draw);
        $img->setImageFormat('jpeg');

        return $img->getImageBlob();
    }

    /** Scan déjà dans MemoryLane, avec son grand aperçu. */
    private function scan(string $name, int $variant): Media
    {
        $disk = Storage::disk(config('filesystems.default'));
        $path = 'media/photos/2026/07/'.uniqid().'.jpg';
        $disk->put($path, $this->picture(300, 200, $variant));
        $tmp = sys_get_temp_dir().'/scan-'.uniqid().'.jpg';
        file_put_contents($tmp, $this->picture(300, 200, $variant));
        $hash = app(PerceptualHashService::class)->fromFile($tmp);
        $this->assertNotNull($hash);
        $media = Media::factory()->create([
            'user_id' => $this->me->id, 'type' => 'photo', 'mime_type' => 'image/jpeg', 'original_name' => $name,
            'file_path' => $path, 'width' => 300, 'height' => 200, 'perceptual_hash' => $hash,
        ]);
        @unlink($tmp);
        $large = str_replace('.jpg', '_large.jpg', $path);
        $disk->put($large, $this->picture(300, 200, $variant));
        MediaConversion::create(['media_id' => $media->id, 'conversion_name' => 'large', 'file_path' => $large, 'width' => 300, 'height' => 200, 'size' => 1000]);

        return $media;
    }

    public function test_la_retouche_devient_la_photo_et_le_scan_reste(): void
    {
        $scan = $this->scan('lettre-1940.jpg', 1);
        $other = $this->scan('autre.jpg', 3);
        $person = Person::factory()->create(['user_id' => $this->me->id]);
        $scan->people()->attach($person->id);
        $oldPath = $scan->file_path;
        File::put("{$this->dir}/lettre-1940-retouche.jpg", $this->picture(600, 400, 1));
        File::put("{$this->dir}/inconnue.jpg", $this->picture(600, 400, 6));

        // Simulation : rien ne change.
        $this->artisan('media:attach-retouched', ['dir' => $this->dir, '--dry-run' => true])->assertSuccessful();
        $this->assertSame($oldPath, $scan->fresh()->file_path);

        $this->artisan('media:attach-retouched', ['dir' => $this->dir])
            ->expectsOutputToContain('inconnue.jpg : aucune photo assez ressemblante')
            ->assertSuccessful();

        $fresh = $scan->fresh();
        $this->assertNotSame($oldPath, $fresh->file_path);
        $this->assertSame($oldPath, $fresh->original_scan_path);
        $this->assertSame(600, $fresh->width);
        $this->assertTrue($fresh->retouched);
        $this->assertNotNull($fresh->retouched_at);
        $disk = Storage::disk(config('filesystems.default'));
        $this->assertTrue($disk->exists($oldPath), 'scan d’origine gardé');
        $this->assertTrue($disk->exists($fresh->original_scan_preview_path), 'aperçu du scan copié à part');
        // Rien ne se perd autour, et l'autre photo n'a pas bougé.
        $this->assertTrue($fresh->people()->whereKey($person->id)->exists());
        $this->assertFalse($other->fresh()->retouched);
        // Chemins jamais envoyés au navigateur.
        $this->assertArrayNotHasKey('original_scan_path', $fresh->toArray());
        $this->assertTrue($fresh->toArray()['retouched']);

        // Bascule : le scan (aperçu et fichier entier) pour qui voit la photo.
        $this->actingAs($this->me)->get("/media/{$scan->id}/file/scan")->assertRedirect();
        $this->actingAs($this->me)->get("/media/{$scan->id}/file/scan-full")->assertRedirect();
        $this->actingAs($this->me)->get("/media/{$other->id}/file/scan")->assertNotFound();
        $this->actingAs(User::factory()->create())->get("/media/{$scan->id}/file/scan")->assertForbidden();

        // Retour au scan d'origine.
        $this->artisan('media:attach-retouched', ['--restore' => [$scan->id]])->assertSuccessful();
        $back = $scan->fresh();
        $this->assertSame($oldPath, $back->file_path);
        $this->assertFalse($back->retouched);
        $this->assertSame(300, $back->width);
    }

    public function test_deux_photos_aussi_proches_c_est_ambigu(): void
    {
        $a = $this->scan('a.jpg', 1);
        $b = $this->scan('b.jpg', 1);
        File::put("{$this->dir}/retouche.jpg", $this->picture(600, 400, 1));

        $this->artisan('media:attach-retouched', ['dir' => $this->dir])
            ->expectsOutputToContain('ambigu')
            ->assertSuccessful();
        $this->assertFalse($a->fresh()->retouched);
        $this->assertFalse($b->fresh()->retouched);
    }

    public function test_le_meme_nom_departage(): void
    {
        $a = $this->scan('portrait-agnes.jpg', 1);
        $b = $this->scan('portrait-emile.jpg', 1);
        File::put("{$this->dir}/portrait-agnes_restored.jpg", $this->picture(600, 400, 1));

        $this->artisan('media:attach-retouched', ['dir' => $this->dir])->assertSuccessful();
        $this->assertTrue($a->fresh()->retouched);
        $this->assertFalse($b->fresh()->retouched);
    }
}
