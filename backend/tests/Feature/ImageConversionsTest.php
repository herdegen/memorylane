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

    /**
     * Miniatures refaites (nouvelle image de vidéo, rotation…) : nouveau nom
     * de fichier, donc nouvelle adresse — sinon le navigateur garde
     * l'ancienne image — et l'ancien fichier est effacé.
     */
    public function test_regenerated_conversions_get_a_new_file_and_the_old_one_is_removed(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);
        $img = new \Imagick();
        $img->newImage(1200, 900, new \ImagickPixel('#cc8866'));
        $img->setImageFormat('jpeg');
        Storage::disk($disk)->put('media/photos/2026/11/refaite.jpg', $img->getImageBlob());
        $img->clear();
        $media = Media::factory()->create([
            'type' => 'photo', 'mime_type' => 'image/jpeg',
            'original_name' => 'refaite.jpg', 'file_path' => 'media/photos/2026/11/refaite.jpg',
        ]);

        (new GenerateMediaConversions($media))->handle(app(S3Service::class));
        $first = $media->conversions()->pluck('file_path', 'conversion_name');

        (new GenerateMediaConversions($media->fresh()))->handle(app(S3Service::class));
        $second = $media->conversions()->pluck('file_path', 'conversion_name');

        $this->assertCount(4, $second);
        foreach ($first as $name => $oldPath) {
            $this->assertNotSame($oldPath, $second[$name], "{$name} : même fichier, le navigateur garderait l'ancienne image");
            Storage::disk($disk)->assertMissing($oldPath);
            Storage::disk($disk)->assertExists($second[$name]);
        }
    }
}
