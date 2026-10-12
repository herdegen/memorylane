<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReplaceOriginalsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $log;

    private ?string $logBefore;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->dir = storage_path('framework/testing/hd-'.uniqid());
        File::makeDirectory($this->dir, 0775, true);
        $this->log = storage_path('app/replace-originals.jsonl');
        $this->logBefore = File::exists($this->log) ? File::get($this->log) : null;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        $this->logBefore === null ? File::delete($this->log) : File::put($this->log, $this->logBefore);
        parent::tearDown();
    }

    /** Image à motifs (dHash discriminant), avec le RAW d'origine en XMP. */
    private function picture(int $width, int $height, int $variant, ?string $raw): string
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
        if ($raw) {
            $img->setImageProfile('xmp', '<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><rdf:Description xmlns:crs="http://ns.adobe.com/camera-raw-settings/1.0/" crs:RawFileName="'.$raw.'"/></rdf:RDF></x:xmpmeta>');
        }

        return $img->getImageBlob();
    }

    private function photo(string $name, int $variant, ?string $raw): Media
    {
        $path = 'media/photos/2026/07/'.uniqid().'.jpg';
        Storage::disk(config('filesystems.default'))->put($path, $this->picture(300, 200, $variant, $raw));

        return Media::factory()->create([
            'user_id' => User::factory(), 'type' => 'photo', 'mime_type' => 'image/jpeg',
            'original_name' => $name, 'file_path' => $path, 'width' => 300, 'height' => 200, 'perceptual_hash' => null,
        ]);
    }

    public function test_replaces_only_matching_photos_and_keeps_the_old_file(): void
    {
        $good = $this->photo('a.jpg', 1, 'DSC_1.NEF');
        $other = $this->photo('b.jpg', 1, 'DSC_2.NEF');
        $retake = $this->photo('c.jpg', 1, 'DSC_3.NEF');
        File::put("{$this->dir}/a.jpg", $this->picture(900, 600, 1, 'DSC_1.NEF'));
        File::put("{$this->dir}/b.jpg", $this->picture(900, 600, 3, 'DSC_2.NEF'));   // autre image
        File::put("{$this->dir}/c.jpg", $this->picture(900, 600, 1, 'DSC_9.NEF'));   // autre prise de vue
        File::put("{$this->dir}/d.jpg", $this->picture(900, 600, 1, null));           // inconnue
        $oldPath = $good->file_path;

        $this->artisan('media:replace-originals', ['dir' => $this->dir, '--dry-run' => true])
            ->expectsOutputToContain('1 prête(s), 3 sautée(s)')->assertSuccessful();
        $this->assertSame($oldPath, $good->fresh()->file_path, 'simulation : rien ne change');

        $this->artisan('media:replace-originals', ['dir' => $this->dir])
            ->expectsOutputToContain('1 remplacée(s), 3 sautée(s)')->assertSuccessful();

        $good->refresh();
        $this->assertNotSame($oldPath, $good->file_path);
        $this->assertSame([900, 600], [$good->width, $good->height]);
        $this->assertSame(hash_file('sha256', "{$this->dir}/a.jpg"), $good->content_hash);
        $this->assertGreaterThanOrEqual(4, $good->conversions()->count());
        Storage::disk(config('filesystems.default'))->assertExists([$oldPath, $good->file_path]);
        $this->assertStringContainsString($oldPath, File::get($this->log));
        $this->assertSame(300, $other->fresh()->width);
        $this->assertSame(300, $retake->fresh()->width);

        // Rejouable : déjà en haute définition → sautée.
        $this->artisan('media:replace-originals', ['dir' => $this->dir, '--only' => ['a.jpg']])
            ->expectsOutputToContain('pas plus grande')->assertSuccessful();
    }
}
