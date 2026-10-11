<?php

namespace Tests\Feature;

use App\Jobs\GenerateMediaConversions;
use App\Models\Media;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageConversionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Grande photo (48 Mpx) : un seul décodage Imagick, hors mémoire PHP —
     * avant, 4 décodages GD pleine taille épuisaient les 512 Mo des workers.
     */
    public function test_large_photo_is_converted_without_exhausting_php_memory(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);

        $img = new \Imagick();
        // newImage (aucun « coder ») : compatible avec la policy ImageMagick restrictive des conteneurs.
        $img->newImage(8000, 6000, new \ImagickPixel('#88aacc'));
        $img->setImageFormat('jpeg');
        $img->setImageCompressionQuality(80);
        Storage::disk($disk)->put('media/photos/2026/10/grande.jpg', $img->getImageBlob());
        $img->clear();

        $media = Media::factory()->create([
            'type' => 'photo', 'mime_type' => 'image/jpeg',
            'original_name' => 'grande.jpg', 'file_path' => 'media/photos/2026/10/grande.jpg',
        ]);

        $before = memory_get_usage();
        memory_reset_peak_usage();
        (new GenerateMediaConversions($media))->handle(app(S3Service::class));
        $peak = memory_get_peak_usage() - $before;

        $sizes = $media->conversions()->get()->mapWithKeys(fn ($c) => [$c->conversion_name => "{$c->width}x{$c->height}"])->all();
        $this->assertSame([
            'large' => '1600x1200',
            'medium' => '800x600',
            'small' => '400x300',
            'thumbnail' => '150x150',
        ], collect($sizes)->sortKeys()->all());
        foreach ($media->conversions as $conversion) {
            Storage::disk($disk)->assertExists($conversion->file_path);
        }
        $this->assertLessThan(64 * 1024 * 1024, $peak, 'Mémoire PHP consommée par la conversion');
    }
}
