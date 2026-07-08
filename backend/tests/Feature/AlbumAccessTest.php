<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\AlbumAccess;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlbumAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->viewer = User::factory()->create();
    }

    /** Album non-public + un média dedans, appartenant à $owner. */
    private function albumWithMedia(array $attrs = []): array
    {
        $album = Album::factory()->create(array_merge(['user_id' => $this->owner->id], $attrs));
        $media = Media::factory()->create(['user_id' => $this->owner->id, 'type' => 'photo']);
        $album->media()->attach($media->id, ['order' => 0]);

        return [$album, $media];
    }

    public function test_owner_can_view_own_album(): void
    {
        [$album] = $this->albumWithMedia();
        $this->actingAs($this->owner)->getJson("/albums/{$album->id}")->assertOk();
    }

    public function test_private_album_not_accessible_to_others(): void
    {
        [$album] = $this->albumWithMedia();
        $this->actingAs($this->viewer)->getJson("/albums/{$album->id}")->assertForbidden();
        $this->assertFalse($album->isAccessibleBy($this->viewer));
    }

    public function test_public_album_accessible_to_any_connected_user(): void
    {
        [$album] = $this->albumWithMedia(['is_public' => true]);
        $this->actingAs($this->viewer)->getJson("/albums/{$album->id}")->assertOk();
        $this->assertTrue($album->isAccessibleBy($this->viewer));
    }

    public function test_granted_user_can_view_then_revoked(): void
    {
        [$album] = $this->albumWithMedia();

        AlbumAccess::create([
            'album_id' => $album->id,
            'user_id' => $this->viewer->id,
            'granted_by' => $this->owner->id,
        ]);
        $this->assertTrue($album->fresh()->isAccessibleBy($this->viewer));
        $this->actingAs($this->viewer)->getJson("/albums/{$album->id}")->assertOk();

        AlbumAccess::where('album_id', $album->id)->where('user_id', $this->viewer->id)->delete();
        $this->assertFalse($album->fresh()->isAccessibleBy($this->viewer));
        $this->actingAs($this->viewer)->getJson("/albums/{$album->id}")->assertForbidden();
    }

    public function test_tagged_user_with_account_can_view_even_private_album(): void
    {
        [$album, $media] = $this->albumWithMedia();

        // Une personne taguée sur la photo, reliée au compte du visiteur.
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        $media->people()->attach($person->id);
        $this->viewer->update(['person_id' => $person->id]);

        $this->assertTrue($album->fresh()->isAccessibleBy($this->viewer->fresh()));
        $this->actingAs($this->viewer->fresh())->getJson("/albums/{$album->id}")->assertOk();
    }

    public function test_tagged_person_without_account_does_not_leak(): void
    {
        [$album, $media] = $this->albumWithMedia();

        // Personne taguée mais reliée à AUCUN compte → aucun accès pour autrui.
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        $media->people()->attach($person->id);

        $this->assertFalse($album->fresh()->isAccessibleBy($this->viewer));
        $this->actingAs($this->viewer)->getJson("/albums/{$album->id}")->assertForbidden();
    }

    public function test_non_owner_cannot_edit_or_make_public(): void
    {
        [$album] = $this->albumWithMedia(['is_public' => true]); // accessible en lecture
        // mais pas en écriture
        $this->actingAs($this->viewer)
            ->putJson("/albums/{$album->id}", ['name' => 'Hacked'])
            ->assertForbidden();
    }
}
