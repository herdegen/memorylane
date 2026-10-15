<?php

namespace Tests\Feature;

use App\Jobs\GenerateMediaConversions;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\MediaService;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Miniature d'une vidéo choisie dans le lecteur. */
class VideoFrameTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_chosen_frame_becomes_the_thumbnail_and_faces_start_over(): void
    {
        Bus::fake([GenerateMediaConversions::class]);
        $owner = User::factory()->create();
        $video = Media::factory()->video()->create(['user_id' => $owner->id, 'duration' => 60]);
        $known = Person::factory()->create(['user_id' => $owner->id]);
        $box = ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5];
        DetectedFace::create(['media_id' => $video->id, 'bounding_box' => $box, 'provider' => 'insightface', 'status' => 'matched', 'person_id' => $known->id]);
        DetectedFace::create(['media_id' => $video->id, 'bounding_box' => $box, 'provider' => 'insightface', 'status' => 'unmatched']);
        $video->people()->attach($known->id, ['face_coordinates' => '{"x":1}']);

        $this->actingAs(User::factory()->create())->postJson("/media/{$video->id}/video-frame", ['time' => 30])->assertForbidden();
        $this->actingAs($owner)->postJson("/media/{$video->id}/video-frame", ['time' => 90])->assertStatus(422);
        $this->actingAs($owner)->postJson("/media/{$video->id}/video-frame", ['time' => 30.5])->assertOk();

        $this->assertSame(30.5, $video->fresh()->thumbnail_at);
        $this->assertSame(0, $video->detectedFaces()->count(), 'cadres de l\'ancienne image effacés');
        $this->assertNull($video->people()->whereKey($known->id)->first()?->pivot->face_coordinates, 'la personne reste, sans cadre');
        Bus::assertDispatched(GenerateMediaConversions::class, fn ($job) => $job->media->is($video));

        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->postJson("/media/{$video->id}/video-frame", ['time' => 10])->assertOk();
    }

    public function test_the_thumbnail_is_taken_at_the_chosen_second(): void
    {
        $disk = config('filesystems.default');
        Storage::fake($disk);
        // 2 s de rouge puis 2 s de bleu.
        $tmp = sys_get_temp_dir().'/rouge-bleu-'.uniqid().'.mp4';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=red:s=320x240:d=2', '-f', 'lavfi', '-i', 'color=c=blue:s=320x240:d=2',
            '-filter_complex', '[0:v][1:v]concat=n=2:v=1[v]', '-map', '[v]', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', $tmp])->throw();
        Storage::disk($disk)->put('media/videos/2026/10/rb.mp4', file_get_contents($tmp));
        unlink($tmp);
        $video = Media::factory()->video()->create(['file_path' => 'media/videos/2026/10/rb.mp4', 'mime_type' => 'video/mp4', 'duration' => 4, 'thumbnail_at' => 3]);

        Bus::fake([\App\Jobs\TranscodeVideoForWeb::class, \App\Jobs\AnalyzeFaces::class]);
        (new GenerateMediaConversions($video))->handle(app(S3Service::class));

        $thumb = $video->conversions()->where('conversion_name', 'small')->sole();
        $image = imagecreatefromstring(Storage::disk($disk)->get($thumb->file_path));
        $rgb = imagecolorat($image, (int) (imagesx($image) / 2), (int) (imagesy($image) / 2));
        $this->assertGreaterThan(($rgb >> 16) & 0xFF, $rgb & 0xFF, 'image bleue (3e seconde), pas rouge');

        // Adresse versionnée : une miniature refaite n'est pas resservie depuis le cache.
        $this->assertStringContainsString('v='.$thumb->updated_at->timestamp, app(MediaService::class)->thumbnailUrl($video->fresh()));
    }
}
