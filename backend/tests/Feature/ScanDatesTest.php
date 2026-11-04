<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\MediaMetadata;
use App\Models\User;
use App\Services\ExifExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Photos papier numérisées : la date EXIF est celle du scan, pas de la photo.
 */
class ScanDatesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
    }

    private function photo(string $takenAt, ?string $model, ?string $exifDate = null, string $make = 'Canon'): Media
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id, 'type' => 'photo', 'taken_at' => $takenAt]);
        MediaMetadata::create([
            'media_id' => $media->id,
            'camera_make' => $make,
            'camera_model' => $model,
            'exif_data' => ['IFD0' => ['Make' => $make, 'Model' => $model, 'DateTime' => $exifDate ?? str_replace('-', ':', $takenAt)]],
        ]);

        return $media;
    }

    public function test_reconnait_scanners_et_logiciels_de_numerisation(): void
    {
        $this->assertTrue(ExifExtractor::isScan(['IFD0' => ['Model' => 'CanoScan LiDE 210']]));
        $this->assertTrue(ExifExtractor::isScan(['IFD0' => ['Model' => 'Perfection V600']]));
        $this->assertTrue(ExifExtractor::isScan(['IFD0' => ['Software' => 'VueScan 9.8']]));
        $this->assertTrue(ExifExtractor::isScan(['IFD0' => ['Software' => 'EPSON Scan']]));
        $this->assertTrue(ExifExtractor::isScan([], 'Google', 'PhotoScan'));
        $this->assertFalse(ExifExtractor::isScan(['IFD0' => ['Make' => 'Canon', 'Model' => 'Canon EOS 500D']]));
        $this->assertFalse(ExifExtractor::isScan(['IFD0' => ['Make' => 'Apple', 'Model' => 'iPhone 7']]));
        $this->assertSame('2018-11-17', ExifExtractor::storedExifDay(['IFD0' => ['DateTime' => '2018:11:17 14:15:56']]));
    }

    public function test_la_commande_retire_la_date_du_scan_et_garde_les_dates_corrigees(): void
    {
        $album = Album::factory()->create(['user_id' => $this->owner->id, 'date' => '1996-12-25']);
        $scanned = $this->photo('2018-11-17 14:15:56', 'CanoScan LiDE 210');
        $inAlbum = $this->photo('2018-11-18 10:00:00', 'CanoScan LiDE 210');
        $album->media()->attach($inAlbum->id);
        $corrected = $this->photo('1984-06-02 12:00:00', 'CanoScan LiDE 210', '2018:11:17 14:20:00');
        $camera = $this->photo('2012-07-07 12:00:00', 'Canon EOS 500D');

        $this->artisan('media:clear-scan-dates', ['--dry-run' => true])->assertSuccessful();
        $this->assertNotNull($scanned->fresh()->taken_at);

        $this->artisan('media:clear-scan-dates')->assertSuccessful();

        $this->assertNull($scanned->fresh()->taken_at);
        $this->assertSame('1996-12-25', $inAlbum->fresh()->taken_at->toDateString());
        $this->assertSame($album->id, $inAlbum->fresh()->taken_at_album_id);
        $this->assertSame('1984-06-02', $corrected->fresh()->taken_at->toDateString());
        $this->assertSame('2012-07-07', $camera->fresh()->taken_at->toDateString());
    }

    public function test_retirer_la_date_depuis_mes_photos(): void
    {
        $album = Album::factory()->create(['user_id' => $this->owner->id, 'date' => '2000-08-01']);
        $paper = $this->photo('2019-03-03 09:00:00', 'Pixel 7', null, 'Google');
        $inAlbum = $this->photo('2019-03-03 09:01:00', 'Pixel 7', null, 'Google');
        $album->media()->attach($inAlbum->id);

        $this->actingAs($this->owner)->postJson('/media/bulk/taken-at', [
            'media_ids' => [$paper->id, $inAlbum->id],
            'taken_at' => null,
        ])->assertOk()->assertJsonPath('message', 'Date retirée de 2 médias');

        $this->assertNull($paper->fresh()->taken_at);
        $this->assertSame('2000-08-01', $inAlbum->fresh()->taken_at->toDateString());
    }
}
