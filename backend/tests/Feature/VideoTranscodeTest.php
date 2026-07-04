<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\VideoMetadataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VideoTranscodeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected VideoMetadataService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->service = new VideoMetadataService();
    }

    protected function makeVideo(array $attributes): Media
    {
        return Media::factory()->video()->create(array_merge(
            ['user_id' => $this->user->id],
            $attributes
        ));
    }

    /**
     * Un MP4 H.264 se lit nativement partout : pas de transcodage.
     */
    public function test_mp4_h264_does_not_need_web_version(): void
    {
        $media = $this->makeVideo(['mime_type' => 'video/mp4', 'video_codec' => 'h264']);

        $this->assertFalse($this->service->needsWebVersion($media));
    }

    /**
     * Un WebM VP9 se lit nativement : pas de transcodage.
     */
    public function test_webm_vp9_does_not_need_web_version(): void
    {
        $media = $this->makeVideo(['mime_type' => 'video/webm', 'video_codec' => 'vp9']);

        $this->assertFalse($this->service->needsWebVersion($media));
    }

    /**
     * Un MKV doit être transcodé même si son codec interne est H.264 :
     * les navigateurs ne lisent pas le conteneur Matroska.
     */
    public function test_mkv_needs_web_version_even_with_h264(): void
    {
        $media = $this->makeVideo(['mime_type' => 'video/x-matroska', 'video_codec' => 'h264']);

        $this->assertTrue($this->service->needsWebVersion($media));
    }

    /**
     * Un MP4 en HEVC doit être transcodé (pas de support natif généralisé).
     */
    public function test_mp4_hevc_needs_web_version(): void
    {
        $media = $this->makeVideo(['mime_type' => 'video/mp4', 'video_codec' => 'hevc']);

        $this->assertTrue($this->service->needsWebVersion($media));
    }

    /**
     * Un AVI doit être transcodé.
     */
    public function test_avi_needs_web_version(): void
    {
        $media = $this->makeVideo(['mime_type' => 'video/x-msvideo', 'video_codec' => 'mpeg4']);

        $this->assertTrue($this->service->needsWebVersion($media));
    }

    /**
     * Codec inconnu (extraction échouée) : on ne tente pas de transcodage.
     */
    public function test_unknown_codec_does_not_need_web_version(): void
    {
        $media = $this->makeVideo(['mime_type' => 'video/x-matroska', 'video_codec' => null]);

        $this->assertFalse($this->service->needsWebVersion($media));
    }

    /**
     * Une photo n'est jamais transcodée.
     */
    public function test_photo_does_not_need_web_version(): void
    {
        $media = Media::factory()->create([
            'user_id'    => $this->user->id,
            'type'       => 'photo',
            'mime_type'  => 'image/jpeg',
        ]);

        $this->assertFalse($this->service->needsWebVersion($media));
    }
}
