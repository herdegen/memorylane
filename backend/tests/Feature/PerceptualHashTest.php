<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\PerceptualHashService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Empreinte perceptuelle dHash (issue #42, tranche 2).
 */
class PerceptualHashTest extends TestCase
{
    use RefreshDatabase;

    /** PNG dégradé de gris horizontal (croissant ou décroissant). */
    private function gradientPng(bool $reversed = false, int $w = 90, int $h = 80): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            $t = $x / ($w - 1);
            $v = (int) round(($reversed ? 1 - $t : $t) * 255);
            $col = imagecolorallocate($img, $v, $v, $v);
            imagefilledrectangle($img, $x, 0, $x, $h - 1, $col);
        }
        ob_start();
        imagepng($img);
        $bin = ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    /** Même dégradé, encodé en JPEG (recompression) pour tester la robustesse. */
    private function gradientJpeg(int $quality = 40, int $w = 90, int $h = 80): string
    {
        $img = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x++) {
            $v = (int) round(($x / ($w - 1)) * 255);
            $col = imagecolorallocate($img, $v, $v, $v);
            imagefilledrectangle($img, $x, 0, $x, $h - 1, $col);
        }
        ob_start();
        imagejpeg($img, null, $quality);
        $bin = ob_get_clean();
        imagedestroy($img);

        return $bin;
    }

    public function test_dhash_est_16_hexa_et_deterministe(): void
    {
        $hasher = app(PerceptualHashService::class);

        $a = $hasher->fromFile($this->gradientPng());
        $b = $hasher->fromFile($this->gradientPng());

        $this->assertNotNull($a);
        $this->assertSame(16, strlen($a));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $a);
        $this->assertSame($a, $b, 'Même image → même empreinte');
    }

    public function test_dhash_dun_degrade_croissant_est_tout_a_un(): void
    {
        // Chaque pixel est plus sombre que son voisin de droite → tous les bits
        // de différence valent 1.
        $this->assertSame('ffffffffffffffff', app(PerceptualHashService::class)->fromFile($this->gradientPng()));
        $this->assertSame('0000000000000000', app(PerceptualHashService::class)->fromFile($this->gradientPng(reversed: true)));
    }

    public function test_hamming_distance(): void
    {
        // Croissant vs décroissant = opposés sur les 64 bits.
        $this->assertSame(64, PerceptualHashService::hammingDistance('ffffffffffffffff', '0000000000000000'));
        $this->assertSame(0, PerceptualHashService::hammingDistance('abcdef0123456789', 'abcdef0123456789'));
        $this->assertSame(1, PerceptualHashService::hammingDistance('0000000000000000', '0000000000000001'));
    }

    public function test_recompression_jpeg_reste_proche(): void
    {
        $hasher = app(PerceptualHashService::class);

        $png = $hasher->fromFile($this->gradientPng());
        $jpeg = $hasher->fromFile($this->gradientJpeg());

        // La recompression ne doit quasiment pas bouger l'empreinte (le cœur du
        // quasi-doublon) : distance très faible.
        $this->assertLessThanOrEqual(4, PerceptualHashService::hammingDistance($png, $jpeg));
    }

    public function test_fromfile_retourne_null_sur_donnees_invalides(): void
    {
        $this->assertNull(app(PerceptualHashService::class)->fromFile('ceci-n-est-pas-une-image'));
    }

    public function test_backfill_command_remplit_les_photos_sans_empreinte(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);

        $admin = User::factory()->create();
        // Le hash se calcule sur la conversion « small », pas sur l'original.
        $media = Media::factory()->photo()->create([
            'user_id' => $admin->id,
            'perceptual_hash' => null,
        ]);
        $media->conversions()->create([
            'conversion_name' => 'small',
            'file_path' => 'media/gradient_small.png',
            'mime_type' => 'image/png',
        ]);
        Storage::disk($disk)->put('media/gradient_small.png', $this->gradientPng());

        // Un média déjà pourvu ne doit pas être recalculé (ni compté en échec).
        $already = Media::factory()->photo()->create([
            'user_id' => $admin->id,
            'perceptual_hash' => 'abcdef0123456789',
        ]);

        // Une photo SANS conversion exploitable est sautée (pas d'échec, pas de
        // décodage de l'original).
        $noConversion = Media::factory()->photo()->create([
            'user_id' => $admin->id,
            'perceptual_hash' => null,
        ]);

        $this->artisan('media:backfill-perceptual-hashes')->assertSuccessful();

        $this->assertSame('ffffffffffffffff', $media->fresh()->perceptual_hash);
        $this->assertSame('abcdef0123456789', $already->fresh()->perceptual_hash);
        $this->assertNull($noConversion->fresh()->perceptual_hash);
    }
}
