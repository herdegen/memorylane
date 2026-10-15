<?php

namespace Tests\Feature;

use App\Jobs\ImportDriveVideo;
use App\Jobs\ImportGoogleDriveFiles;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\GoogleOAuth;
use App\Services\MediaService;
use App\Services\VideoOptimizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VideoOptimizerTest extends TestCase
{
    use RefreshDatabase;

    private array $tmp = [];

    protected function tearDown(): void
    {
        array_map(fn ($f) => @unlink($f), $this->tmp);
        parent::tearDown();
    }

    /** Vidéo de caméscope numérisée : MPEG-2 entrelacé, PAL 16/9 (pixels rectangulaires), son. */
    private function camcorderVideo(): string
    {
        $path = $this->tmp[] = sys_get_temp_dir().'/camescope-'.uniqid().'.mpg';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=720x576:rate=25', '-f', 'lavfi', '-i', 'sine=frequency=440',
            '-t', '3', '-c:v', 'mpeg2video', '-b:v', '8M', '-flags', '+ilme+ildct', '-top', '1', '-aspect', '16:9', '-c:a', 'mp2', $path])->throw();

        return $path;
    }

    public function test_an_old_camcorder_video_is_optimized(): void
    {
        $optimizer = app(VideoOptimizer::class);
        $source = $this->camcorderVideo();
        $before = $optimizer->probe($source);
        $this->assertSame(['mpeg2video', true], [$before['codec'], $before['interlaced']]);
        $this->assertTrue($optimizer->needsOptimization($before));

        $output = $this->tmp[] = sys_get_temp_dir().'/optimise-'.uniqid().'.mp4';
        $optimizer->optimize($source, $output, $before);
        $after = $optimizer->probe($output);

        $this->assertSame('h264', $after['codec']);
        $this->assertFalse($after['interlaced'], 'dés-entrelacée');
        $this->assertSame([1024, 576], [$after['width'], $after['height']], 'pixels carrés, 16/9 conservé');
        $this->assertTrue($after['has_audio']);
        $this->assertLessThan(filesize($source) / 2, filesize($output));
        $this->assertFalse($optimizer->needsOptimization($after), 'rien à refaire');
    }

    public function test_an_efficient_phone_video_is_kept_as_is(): void
    {
        $path = $this->tmp[] = sys_get_temp_dir().'/telephone-'.uniqid().'.mp4';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=1280x720:rate=30', '-t', '2',
            '-c:v', 'libx264', '-crf', '23', '-pix_fmt', 'yuv420p', $path])->throw();

        $this->assertFalse(app(VideoOptimizer::class)->needsOptimization(app(VideoOptimizer::class)->probe($path)));
    }

    public function test_drive_videos_are_optimized_apart_and_only_the_optimized_copy_is_kept(): void
    {
        Bus::fake([\App\Jobs\GenerateMediaConversions::class, \App\Jobs\ProcessUploadedMedia::class]);
        Storage::fake('public');
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id]);
        $video = file_get_contents($this->camcorderVideo());
        Http::fake(function (HttpRequest $request) use ($video) {
            return str_contains($request->url(), 'alt=media')
                ? Http::response($video, 200, ['Content-Type' => 'video/mpeg'])
                : Http::response(['id' => 'cam1', 'name' => 'Mariage 1998.mpg', 'mimeType' => 'video/mpeg', 'size' => (string) strlen($video)]);
        });

        // L'import groupé confie la vidéo à la file « video ».
        Bus::fake([ImportDriveVideo::class]);
        (new ImportGoogleDriveFiles($user->id, 'ya29.valid', null, ['cam1'], $person->id))->handle(app(MediaService::class), app(GoogleOAuth::class));
        Bus::assertDispatched(ImportDriveVideo::class, fn ($job) => $job->fileId === 'cam1' && $job->queue === 'video' && $job->connection === 'redis-long');
        $this->actingAs($user)->getJson('/google-drive/imported')->assertJson(['finished' => true, 'skipped' => 0, 'videos' => 1]);

        $job = new ImportDriveVideo($user->id, 'ya29.valid', null, 'cam1', 'Mariage 1998.mpg', 'video/mpeg', $person->id);
        $job->handle(app(MediaService::class), app(GoogleOAuth::class));
        $job->handle(app(MediaService::class), app(GoogleOAuth::class)); // réimport : pas de doublon

        $media = Media::where('user_id', $user->id)->sole();
        $this->assertSame(['Mariage 1998.mp4', 'video/mp4'], [$media->original_name, $media->mime_type]);
        $this->assertLessThan(strlen($video) / 2, $media->size, 'seule la version optimisée est stockée');
        $this->assertTrue($media->people()->whereKey($person->id)->exists());
    }
}
