<?php

namespace Tests\Feature;

use App\Jobs\ExtractVideoMetadata;
use App\Jobs\GenerateMediaConversions;
use App\Jobs\ProcessUploadedMedia;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('public');
    }

    /**
     * Vérifie que ExtractVideoMetadata est dispatché lors de l'upload d'une vidéo.
     */
    public function test_extract_video_metadata_job_is_dispatched_for_video_upload(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->create('test-video.mp4', 1024, 'video/mp4');

        $this->actingAs($this->user)->postJson('/media', ['file' => $file]);

        Bus::assertDispatched(ExtractVideoMetadata::class);
        Bus::assertDispatched(GenerateMediaConversions::class);
        Bus::assertDispatched(ProcessUploadedMedia::class);
    }

    /**
     * Vérifie que ExtractVideoMetadata n'est PAS dispatché pour une photo.
     */
    public function test_extract_video_metadata_job_is_not_dispatched_for_photo_upload(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->image('test-photo.jpg', 1920, 1080);

        $this->actingAs($this->user)->postJson('/media', ['file' => $file]);

        Bus::assertNotDispatched(ExtractVideoMetadata::class);
        Bus::assertDispatched(ProcessUploadedMedia::class);
        Bus::assertDispatched(GenerateMediaConversions::class);
    }

    /**
     * Vérifie que le modèle Media accepte les nouveaux champs vidéo.
     */
    public function test_media_model_accepts_video_metadata_fields(): void
    {
        $media = Media::factory()->video()->create([
            'user_id'     => $this->user->id,
            'duration'    => 120,
            'width'       => 1920,
            'height'      => 1080,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'fps'         => 29.97,
            'bitrate'     => 4500,
        ]);

        $this->assertDatabaseHas('media', [
            'id'          => $media->id,
            'video_codec' => 'h264',
            'audio_codec' => 'aac',
            'bitrate'     => 4500,
        ]);
        $this->assertEquals(29.97, round((float) $media->fresh()->fps, 2));
    }

    /**
     * Vérifie l'accessor resolution_label.
     */
    public function test_resolution_label_accessor(): void
    {
        $cases = [
            [2160, '4K'],
            [1080, '1080p'],
            [720, '720p'],
            [480, '480p'],
        ];

        foreach ($cases as [$height, $expected]) {
            $media = Media::factory()->video()->create([
                'user_id' => $this->user->id,
                'height'  => $height,
                'width'   => (int) round($height * 16 / 9),
            ]);
            $this->assertEquals($expected, $media->resolution_label, "Échec pour height={$height}");
        }
    }

    /**
     * Vérifie que resolution_label retourne null si height est null.
     */
    public function test_resolution_label_returns_null_when_no_height(): void
    {
        $media = Media::factory()->video()->create([
            'user_id' => $this->user->id,
            'height'  => null,
            'width'   => null,
        ]);

        $this->assertNull($media->resolution_label);
    }

    /**
     * Vérifie que la page de détail d'une vidéo inclut resolution_label dans les props.
     */
    public function test_show_page_includes_resolution_label_for_video(): void
    {
        $media = Media::factory()->video()->create([
            'user_id' => $this->user->id,
            'height'  => 1080,
            'width'   => 1920,
        ]);

        $response = $this->actingAs($this->user)->get("/media/{$media->id}");

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) =>
            $page->where('media.resolution_label', '1080p')
        );
    }
}
