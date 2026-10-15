<?php

namespace Tests\Feature;

use App\Jobs\GenerateHeroClip;
use App\Jobs\GenerateMediaConversions;
use App\Jobs\SplitVideoIntoClips;
use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Découpe réelle (ffmpeg) : les clips prennent la place de la vidéo source. */
class VideoClipSplitTest extends TestCase
{
    use RefreshDatabase;

    public function test_who_sees_a_clip_can_open_its_video_and_the_other_way_round(): void
    {
        $owner = User::factory()->create();
        $source = Media::factory()->video()->create(['user_id' => $owner->id, 'is_source' => true]);
        $clip = Media::factory()->video()->create(['user_id' => $owner->id, 'source_media_id' => $source->id]);
        $album = Album::factory()->create(['user_id' => $owner->id]);
        $album->media()->attach($clip->id, ['order' => 0]);
        $viewer = User::factory()->create();
        $circle = Household::factory()->create(['created_by' => $owner->id]);
        $circle->members()->attach($viewer->id);
        $album->households()->attach($circle->id, ['granted_by' => $owner->id]);

        $this->assertTrue($viewer->can('view', $clip), 'clip vu par l\'album');
        $this->assertTrue($viewer->can('view', $source), 'donc la vidéo entière aussi');

        $other = Media::factory()->video()->create(['user_id' => $owner->id, 'is_source' => true]);
        $otherClip = Media::factory()->video()->create(['user_id' => $owner->id, 'source_media_id' => $other->id]);
        $other->households()->attach($circle->id, ['added_by' => $owner->id]);
        $this->assertTrue($viewer->can('view', $otherClip), 'vidéo partagée : ses clips aussi');

        $stranger = User::factory()->create();
        $this->assertFalse($stranger->can('view', $source));
        $this->assertFalse($stranger->can('view', $clip));
    }

    public function test_clips_inherit_the_source_links_and_replace_it_in_its_albums(): void
    {
        Bus::fake([GenerateMediaConversions::class, GenerateHeroClip::class]);
        $disk = config('filesystems.default');
        Storage::fake($disk);
        $tmp = sys_get_temp_dir().'/source-'.uniqid().'.mp4';
        Process::run(['ffmpeg', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=320x240:rate=25', '-f', 'lavfi', '-i', 'sine', '-t', '3',
            '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', $tmp])->throw();
        Storage::disk($disk)->put('media/videos/2026/10/source.mp4', file_get_contents($tmp));
        unlink($tmp);

        $user = User::factory()->create();
        $source = Media::factory()->video()->create([
            'user_id' => $user->id, 'file_path' => 'media/videos/2026/10/source.mp4', 'mime_type' => 'video/mp4',
            'duration' => 3, 'width' => 320, 'height' => 240, 'taken_at' => '1957-08-01 10:00:00', 'original_name' => 'Vacances.mp4',
        ]);
        $album = Album::factory()->create(['user_id' => $user->id]);
        $other = Media::factory()->create(['user_id' => $user->id]);
        $album->media()->attach([$other->id => ['order' => 1], $source->id => ['order' => 5]]);
        $album->forceFill(['cover_media_id' => $source->id, 'hero_media_id' => $source->id])->save();
        $person = Person::factory()->create(['user_id' => $user->id]);
        $source->people()->attach($person->id, ['face_coordinates' => '{"x":1}']);
        $circle = Household::factory()->create(['created_by' => $user->id]);
        $source->households()->attach($circle->id, ['added_by' => $user->id]);
        $tag = Tag::create(['user_id' => $user->id, 'name' => 'Plage']);
        $source->tags()->attach($tag->id);

        (new SplitVideoIntoClips($source, [['start' => 0, 'end' => 1.5, 'title' => 'Arrivée'], ['start' => 1.5, 'end' => 3]]))
            ->handle(app(S3Service::class));

        $clips = Media::where('source_media_id', $source->id)->orderBy('clip_start')->get();
        $this->assertCount(2, $clips);
        $this->assertTrue($source->fresh()->is_source);
        foreach ($clips as $clip) {
            $this->assertSame('1957-08-01', $clip->taken_at->toDateString());
            $this->assertTrue($clip->people()->whereKey($person->id)->exists());
            $this->assertTrue($clip->households()->whereKey($circle->id)->exists());
            $this->assertTrue($clip->tags()->whereKey($tag->id)->exists());
        }

        // L'album montre les clips à la place de la vidéo entière.
        $this->assertEqualsCanonicalizing([$other->id, ...$clips->pluck('id')], $album->media()->pluck('media.id')->all());
        $this->assertSame(5, (int) $album->media()->whereKey($clips[0]->id)->first()->pivot->order);
        $album->refresh();
        $this->assertSame([$clips[0]->id, $clips[0]->id], [$album->cover_media_id, $album->hero_media_id]);
        Bus::assertDispatched(GenerateHeroClip::class, fn ($job) => $job->video->is($clips[0]));
    }
}
