<?php

namespace Tests\Feature;

use App\Jobs\GenerateHeroClip;
use App\Models\Album;
use App\Models\Media;
use App\Models\MediaConversion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AlbumHeroTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Album $album;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('filesystems.default'));
        $this->owner = User::factory()->create();
        $this->album = Album::factory()->create(['user_id' => $this->owner->id]);
    }

    private function inAlbum(array $attrs): Media
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id, ...$attrs]);
        $this->album->media()->attach($media->id, ['order' => 0]);

        return $media;
    }

    public function test_a_video_becomes_a_looping_header(): void
    {
        $disk = config('filesystems.default');
        // Vraie petite vidéo HEVC (comme celles des téléphones), 3 s.
        $tmp = sys_get_temp_dir().'/hero-test-'.uniqid().'.mp4';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=30', '-t', '3', '-c:v', 'libx265', '-pix_fmt', 'yuv420p', $tmp])->throw();
        Storage::disk($disk)->put('media/videos/2026/10/drone.mp4', file_get_contents($tmp));
        unlink($tmp);
        $video = $this->inAlbum(['type' => 'video', 'mime_type' => 'video/mp4', 'file_path' => 'media/videos/2026/10/drone.mp4', 'duration' => 3]);

        $this->artisan('album:hero', ['album' => $this->album->id, 'media' => $video->id, '--start' => 0, '--duration' => 2])->assertSuccessful();

        $clip = $video->conversions()->where('conversion_name', 'hero')->sole();
        $this->assertSame([640, 'video/mp4'], [$clip->width, $clip->mime_type], 'jamais agrandie');
        Storage::disk($disk)->assertExists([$clip->file_path, $video->conversions()->where('conversion_name', 'hero_poster')->sole()->file_path]);
        $codec = Process::run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=codec_name', '-of', 'csv=p=0', Storage::disk($disk)->path($clip->file_path)])->output();
        $this->assertSame('h264', trim($codec), 'lisible partout, même depuis du HEVC');

        $this->actingAs($this->owner)->get("/albums/{$this->album->id}")
            ->assertInertia(fn ($page) => $page
                ->where('album.hero.type', 'video')
                ->where('album.hero.src', fn ($src) => str_contains($src, "/media/{$video->id}/file/hero"))
                ->where('album.hero.poster', fn ($src) => str_contains($src, '/file/hero_poster')));

        // Retirée de l'album : plus de fond vidéo.
        $this->album->media()->detach($video->id);
        $this->actingAs($this->owner)->get("/albums/{$this->album->id}")->assertInertia(fn ($page) => $page->where('album.hero', null));
        $this->artisan('album:hero', ['album' => $this->album->id, '--off' => true])->assertSuccessful();
        $this->assertNull($this->album->fresh()->hero_media_id);
    }

    public function test_owner_picks_a_photo_or_a_video_from_the_album(): void
    {
        Queue::fake();
        $photo = $this->inAlbum(['type' => 'photo']);
        MediaConversion::create(['media_id' => $photo->id, 'conversion_name' => 'large', 'file_path' => 'x_large.jpg', 'mime_type' => 'image/jpeg', 'size' => 1]);
        $video = $this->inAlbum(['type' => 'video', 'duration' => 60]);

        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/hero", ['media_id' => $photo->id])->assertOk();
        $this->actingAs($this->owner)->get("/albums/{$this->album->id}")
            ->assertInertia(fn ($page) => $page->where('album.hero.type', 'photo')
                ->where('album.hero.src', fn ($src) => str_contains($src, "/media/{$photo->id}/file/large")));

        // Vidéo : extrait préparé en tâche de fond, image de la vidéo en attendant.
        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/hero", ['media_id' => $video->id, 'start' => 20])
            ->assertOk()->assertJson(['preparing' => true]);
        Queue::assertPushed(GenerateHeroClip::class, fn ($job) => $job->video->is($video) && $job->start === 20.0);
        $this->actingAs($this->owner)->get("/albums/{$this->album->id}")
            ->assertInertia(fn ($page) => $page->where('album.hero.type', 'video')->where('album.hero.src', null));

        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/hero", ['media_id' => $video->id, 'start' => 90])->assertStatus(422);
        $outsider = Media::factory()->create(['user_id' => $this->owner->id, 'type' => 'photo']);
        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/hero", ['media_id' => $outsider->id])->assertStatus(422);
        $this->actingAs(User::factory()->create())->postJson("/albums/{$this->album->id}/hero", ['media_id' => $photo->id])->assertForbidden();

        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/hero", ['media_id' => null])->assertOk();
        $this->assertNull($this->album->fresh()->hero_media_id);
    }
}
