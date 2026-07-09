<?php

namespace Tests\Feature;

use App\Jobs\SplitVideoIntoClips;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class VideoClipTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
    }

    protected function makeVideo(array $attributes = []): Media
    {
        return Media::factory()->video()->create(array_merge(
            ['user_id' => $this->user->id, 'duration' => 120],
            $attributes,
        ));
    }

    public function test_owner_can_launch_clip_splitting(): void
    {
        Queue::fake();
        $video = $this->makeVideo();

        $response = $this->actingAs($this->user)->postJson("/media/{$video->id}/clips", [
            'segments' => [
                ['start' => 0, 'end' => 45, 'title' => 'Arrivée'],
                ['start' => 70, 'end' => 110],
            ],
        ]);

        $response->assertStatus(202)->assertJson(['count' => 2]);

        Queue::assertPushed(SplitVideoIntoClips::class, function ($job) use ($video) {
            return $job->media->id === $video->id && count($job->segments) === 2;
        });
    }

    public function test_cannot_split_other_users_video(): void
    {
        Queue::fake();
        $video = $this->makeVideo(['user_id' => $this->otherUser->id]);

        $this->actingAs($this->user)->postJson("/media/{$video->id}/clips", [
            'segments' => [['start' => 0, 'end' => 10]],
        ])->assertStatus(403);

        Queue::assertNothingPushed();
    }

    public function test_cannot_split_a_photo(): void
    {
        Queue::fake();
        $photo = Media::factory()->photo()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)->postJson("/media/{$photo->id}/clips", [
            'segments' => [['start' => 0, 'end' => 10]],
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_cannot_re_split_a_clip(): void
    {
        Queue::fake();
        $source = $this->makeVideo();
        $clip = $this->makeVideo(['source_media_id' => $source->id]);

        $this->actingAs($this->user)->postJson("/media/{$clip->id}/clips", [
            'segments' => [['start' => 0, 'end' => 10]],
        ])->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_rejects_empty_segments(): void
    {
        Queue::fake();
        $video = $this->makeVideo();

        $this->actingAs($this->user)->postJson("/media/{$video->id}/clips", [
            'segments' => [],
        ])->assertStatus(422)->assertJsonValidationErrors(['segments']);
    }

    public function test_rejects_end_before_start(): void
    {
        Queue::fake();
        $video = $this->makeVideo();

        $this->actingAs($this->user)->postJson("/media/{$video->id}/clips", [
            'segments' => [['start' => 30, 'end' => 10]],
        ])->assertStatus(422)->assertJsonValidationErrors(['segments.0.end']);
    }

    public function test_rejects_end_past_duration(): void
    {
        Queue::fake();
        $video = $this->makeVideo(['duration' => 60]);

        $this->actingAs($this->user)->postJson("/media/{$video->id}/clips", [
            'segments' => [['start' => 0, 'end' => 500]],
        ])->assertStatus(422)->assertJsonValidationErrors(['segments.0.end']);
    }

    public function test_source_video_is_hidden_from_gallery(): void
    {
        $source = $this->makeVideo(['is_source' => true]);
        $normal = $this->makeVideo(['is_source' => false]);

        $response = $this->actingAs($this->user)->getJson('/media');

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');

        $this->assertFalse($ids->contains($source->id), 'La source découpée ne doit pas apparaître.');
        $this->assertTrue($ids->contains($normal->id), 'Une vidéo normale doit apparaître.');
    }
}
