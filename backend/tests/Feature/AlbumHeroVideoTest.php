<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AlbumHeroVideoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_video_of_the_album_becomes_a_looping_header(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);
        // Vraie petite vidéo HEVC (comme celles des téléphones), 3 s.
        $tmp = sys_get_temp_dir().'/hero-test-'.uniqid().'.mp4';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=30', '-t', '3', '-c:v', 'libx265', '-pix_fmt', 'yuv420p', $tmp])->throw();
        Storage::disk($disk)->put('media/videos/2026/10/drone.mp4', file_get_contents($tmp));
        unlink($tmp);

        $owner = User::factory()->create();
        $video = Media::factory()->create([
            'user_id' => $owner->id, 'type' => 'video', 'mime_type' => 'video/mp4',
            'file_path' => 'media/videos/2026/10/drone.mp4', 'duration' => 3,
        ]);
        $album = Album::factory()->create(['user_id' => $owner->id]);
        $album->media()->attach($video->id, ['order' => 0]);

        $this->artisan('album:hero-video', ['album' => $album->id, 'media' => $video->id, '--start' => 0, '--duration' => 2])
            ->assertSuccessful();

        $clip = $video->conversions()->where('conversion_name', 'hero')->sole();
        $this->assertSame('video/mp4', $clip->mime_type);
        $this->assertSame(640, $clip->width);
        Storage::disk($disk)->assertExists([$clip->file_path, $video->conversions()->where('conversion_name', 'hero_poster')->sole()->file_path]);
        $codec = Process::run(['ffprobe', '-v', 'error', '-select_streams', 'v:0', '-show_entries', 'stream=codec_name', '-of', 'csv=p=0', Storage::disk($disk)->path($clip->file_path)])->output();
        $this->assertSame('h264', trim($codec), 'lisible partout, même depuis du HEVC');

        $this->actingAs($owner)->get("/albums/{$album->id}")
            ->assertInertia(fn ($page) => $page
                ->where('album.hero_video.src', fn ($src) => str_contains($src, "/media/{$video->id}/file/hero"))
                ->where('album.hero_video.poster', fn ($src) => str_contains($src, '/file/hero_poster')));

        // Retirée de l'album : plus de fond vidéo ; --off le retire aussi.
        $album->media()->detach($video->id);
        $this->actingAs($owner)->get("/albums/{$album->id}")->assertInertia(fn ($page) => $page->where('album.hero_video', null));
        $this->artisan('album:hero-video', ['album' => $album->id, '--off' => true])->assertSuccessful();
        $this->assertNull($album->fresh()->hero_video_id);
    }
}
