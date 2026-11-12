<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le chemin des photos sur un VRAI stockage S3 (Garage, faux Scaleway jetable
 * démarré par scripts/test.sh), et non sur le disque local des autres tests :
 * envoi, miniatures, date EXIF relue depuis le stockage, liens présignés,
 * envoi en plusieurs morceaux, purge, et écriture refusée.
 *
 * Origine : incident de juillet 2026 (photos importées jamais arrivées sur S3,
 * sans erreur visible). Sans Garage (lancement hors test.sh), ces tests sont
 * sautés, ce qui fait échouer la validation de test.sh : c'est voulu.
 */
#[Group('s3')]
class S3StorageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('S3_TEST_ENDPOINT')) {
            $this->markTestSkipped('Stockage S3 de test absent : lancer scripts/test.sh (démarre Garage).');
        }

        // Même disque que la prod (scaleway, throw => false), pointé sur Garage.
        config([
            'filesystems.disks.scaleway' => array_merge(config('filesystems.disks.scaleway'), [
                'key' => env('S3_TEST_KEY'),
                'secret' => env('S3_TEST_SECRET'),
                'region' => 'garage',
                'bucket' => env('S3_TEST_BUCKET'),
                'endpoint' => env('S3_TEST_ENDPOINT'),
                'url' => null,
            ]),
            'filesystems.default' => 'scaleway',
        ]);
        Storage::forgetDisk('scaleway');

        // Espace de test vidé à chaque test (il ne sert qu'aux tests).
        Storage::disk('scaleway')->delete(Storage::disk('scaleway')->allFiles());

        $this->user = User::factory()->create();
    }

    /**
     * Vraie photo JPEG avec une date de prise de vue dans son EXIF
     * (DateTimeOriginal), pour vérifier qu'elle est relue depuis le stockage.
     */
    private function jpegTakenAt(string $exifDate): string
    {
        $img = imagecreatetruecolor(1600, 1200);
        for ($x = 0; $x < 1600; $x += 40) {
            imagefilledrectangle($img, $x, 0, $x + 39, 1199, imagecolorallocate($img, $x % 256, 120, 255 - $x % 256));
        }
        ob_start();
        imagejpeg($img, null, 85);
        $jpeg = ob_get_clean();

        // TIFF petit-boutiste : IFD0 → pointeur Exif → DateTimeOriginal.
        $tiff = 'II' . pack('v', 42) . pack('V', 8)
            . pack('v', 1) . pack('vvVV', 0x8769, 4, 1, 26) . pack('V', 0)
            . pack('v', 1) . pack('vvVV', 0x9003, 2, 20, 44) . pack('V', 0)
            . $exifDate . "\0";
        $app1 = "\xFF\xE1" . pack('n', strlen($tiff) + 8) . "Exif\0\0" . $tiff;

        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    private function uploadPhoto(string $content): Media
    {
        $this->actingAs($this->user)
            ->post('/media', ['file' => UploadedFile::fake()->createWithContent('anniversaire.jpg', $content)], ['Accept' => 'application/json'])
            ->assertCreated();

        return Media::where('user_id', $this->user->id)->latest('created_at')->firstOrFail()->load('conversions');
    }

    public function test_photo_upload_lands_on_s3_with_thumbnails_and_exif_date(): void
    {
        $jpeg = $this->jpegTakenAt('1987:07:14 15:30:00');
        $media = $this->uploadPhoto($jpeg);
        $disk = Storage::disk('scaleway');

        // L'original est sur le stockage, à l'octet près.
        $this->assertTrue($disk->exists($media->file_path), 'Original absent du stockage');
        $this->assertSame(hash('sha256', $jpeg), hash('sha256', $disk->get($media->file_path)));
        $this->assertSame(hash('sha256', $jpeg), $media->content_hash);

        // Date relue dans le fichier téléchargé depuis le stockage.
        $this->assertSame('1987-07-14 15:30', $media->fresh()->taken_at?->format('Y-m-d H:i'));

        // Miniatures fabriquées et stockées, de vraies images plus petites.
        $this->assertNotEmpty($media->conversions, 'Aucune miniature créée');
        foreach ($media->conversions as $conversion) {
            $this->assertTrue($disk->exists($conversion->file_path), "Miniature {$conversion->conversion_name} absente");
            $size = getimagesizefromstring($disk->get($conversion->file_path));
            $this->assertNotFalse($size, "Miniature {$conversion->conversion_name} illisible");
            $this->assertLessThanOrEqual(1600, $size[0]);
        }
    }

    public function test_protected_file_link_serves_the_file_through_a_presigned_url(): void
    {
        $jpeg = $this->jpegTakenAt('2001:01:01 10:00:00');
        $media = $this->uploadPhoto($jpeg);

        $location = $this->actingAs($this->user)->get("/media/{$media->id}/file")->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('X-Amz-Signature', $location);
        $this->assertSame(hash('sha256', $jpeg), hash('sha256', Http::get($location)->throw()->body()));

        // Une miniature passe aussi par un lien présigné valide.
        $thumb = $media->conversions->first();
        $thumbLocation = $this->actingAs($this->user)->get("/media/{$media->id}/file/{$thumb->conversion_name}")->headers->get('Location');
        $this->assertNotFalse(getimagesizefromstring(Http::get($thumbLocation)->throw()->body()));

        // Sans session, pas de lien.
        auth()->logout();
        $this->get("/media/{$media->id}/file")->assertRedirect('/login');
    }

    public function test_refused_write_creates_no_phantom_media(): void
    {
        config(['filesystems.disks.scaleway.bucket' => 'espace-inexistant']);
        Storage::forgetDisk('scaleway');

        $this->actingAs($this->user)
            ->post('/media', ['file' => UploadedFile::fake()->createWithContent('perdue.jpg', $this->jpegTakenAt('2001:01:01 10:00:00'))], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertSame(0, Media::count(), 'Média créé alors que le fichier n\'est jamais arrivé sur le stockage');
    }

    /**
     * putFile (miniatures, photos iPhone converties, remplacement HD, vidéos
     * réoptimisées) ne doit pas réussir en silence quand le stockage refuse.
     */
    public function test_put_file_refused_write_raises(): void
    {
        config(['filesystems.disks.scaleway.bucket' => 'espace-inexistant']);
        Storage::forgetDisk('scaleway');
        $local = tempnam(sys_get_temp_dir(), 's3t');
        file_put_contents($local, 'contenu');

        try {
            $this->expectExceptionMessage('refusé l\'écriture');
            app(S3Service::class)->putFile($local, 'photos/test.jpg');
        } finally {
            @unlink($local);
        }
    }

    public function test_multipart_upload_from_the_browser_creates_the_media(): void
    {
        $jpeg = $this->jpegTakenAt('1999:12:31 23:59:00');

        $session = $this->actingAs($this->user)->postJson('/media/uploads/initiate', [
            'original_name' => 'gros-fichier.jpg', 'mime_type' => 'image/jpeg', 'size' => strlen($jpeg),
        ])->assertCreated()->json();
        $this->assertSame(1, $session['part_count']);

        // Le navigateur envoie la part directement au stockage (URL présignée).
        $url = $this->actingAs($this->user)->postJson('/media/uploads/part-url', [
            'upload_session_id' => $session['upload_session_id'], 'part_number' => 1,
        ])->assertOk()->json('url');
        $etag = Http::withBody($jpeg, 'image/jpeg')->put($url)->throw()->header('ETag');

        $id = $this->actingAs($this->user)->postJson('/media/uploads/complete', [
            'upload_session_id' => $session['upload_session_id'],
            'parts' => [['part_number' => 1, 'etag' => $etag]],
        ])->assertCreated()->json('media.id');

        $media = Media::with('conversions')->findOrFail($id);
        $this->assertSame(hash('sha256', $jpeg), hash('sha256', Storage::disk('scaleway')->get($media->file_path)));
        $this->assertNotEmpty($media->conversions, 'Aucune miniature après un envoi en plusieurs morceaux');
        $this->assertSame('1999-12-31', $media->fresh()->taken_at?->format('Y-m-d'));
    }

    public function test_force_delete_purges_original_and_thumbnails_from_storage(): void
    {
        $media = $this->uploadPhoto($this->jpegTakenAt('2010:05:05 12:00:00'));
        $paths = $media->conversions->pluck('file_path')->push($media->file_path)->all();
        $disk = Storage::disk('scaleway');

        // Corbeille : les fichiers restent (restauration possible).
        $media->delete();
        foreach ($paths as $path) {
            $this->assertTrue($disk->exists($path), "{$path} effacé dès la corbeille");
        }

        // Suppression définitive : tout part du stockage.
        $media->forceDelete();
        foreach ($paths as $path) {
            $this->assertFalse($disk->exists($path), "{$path} resté sur le stockage après suppression définitive");
        }
    }
}
