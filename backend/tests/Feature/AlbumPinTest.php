<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlbumPinTest extends TestCase
{
    use RefreshDatabase;

    public function test_pinned_media_come_first_in_the_chosen_order(): void
    {
        $owner = User::factory()->create();
        $album = Album::factory()->create(['user_id' => $owner->id]);
        $photos = collect(['2020-08-08 10:00', '2020-08-08 11:00', '2020-08-08 12:00', '2020-08-08 13:00'])
            ->map(fn ($at) => Media::factory()->create(['user_id' => $owner->id, 'type' => 'photo', 'taken_at' => $at]));
        $album->media()->attach($photos->pluck('id')->mapWithKeys(fn ($id, $i) => [$id => ['order' => $i]])->all());
        [$first, $second, $third, $fourth] = $photos->all();

        $this->actingAs($owner)->postJson("/albums/{$album->id}/pin", ['media_ids' => [$fourth->id, $second->id], 'pinned' => true])->assertOk();
        $this->assertSame([$fourth->id, $second->id, $first->id, $third->id], $album->media()->pluck('media.id')->all());

        // Ajout à la suite, puis retrait : numérotation resserrée.
        $this->actingAs($owner)->postJson("/albums/{$album->id}/pin", ['media_ids' => [$first->id], 'pinned' => true])->assertOk();
        $this->actingAs($owner)->postJson("/albums/{$album->id}/pin", ['media_ids' => [$fourth->id], 'pinned' => false])->assertOk();
        $this->assertSame([$second->id, $first->id, $third->id, $fourth->id], $album->media()->pluck('media.id')->all());
        $this->assertSame([0, 1], $album->media()->whereNotNull('album_media.pin_order')->pluck('album_media.pin_order')->all());

        $this->actingAs($owner)->get("/albums/{$album->id}")
            ->assertInertia(fn ($page) => $page
                ->where('album.media.0.id', $second->id)
                ->where('album.media.0.pivot.pin_order', 0)
                ->where('album.media.2.pivot.pin_order', null));
    }

    public function test_only_who_manages_the_album_can_pin(): void
    {
        $album = Album::factory()->create();
        $media = Media::factory()->create(['user_id' => $album->user_id]);
        $album->media()->attach($media->id, ['order' => 0]);

        $this->actingAs(User::factory()->create())
            ->postJson("/albums/{$album->id}/pin", ['media_ids' => [$media->id], 'pinned' => true])
            ->assertForbidden();
    }
}
