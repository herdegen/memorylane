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

    // --- Endpoints d'accès (Phase 2) ---

    public function test_owner_grants_and_revokes_access(): void
    {
        [$album] = $this->albumWithMedia();

        $this->actingAs($this->owner)
            ->postJson("/albums/{$album->id}/access", ['user_id' => $this->viewer->id])
            ->assertCreated();
        $this->assertTrue($album->fresh()->isAccessibleBy($this->viewer));

        $this->actingAs($this->owner)
            ->deleteJson("/albums/{$album->id}/access", ['user_id' => $this->viewer->id])
            ->assertOk();
        $this->assertFalse($album->fresh()->isAccessibleBy($this->viewer));
    }

    public function test_user_without_access_cannot_grant(): void
    {
        [$album] = $this->albumWithMedia();
        $stranger = User::factory()->create();

        $this->actingAs($this->viewer) // pas d'accès
            ->postJson("/albums/{$album->id}/access", ['user_id' => $stranger->id])
            ->assertForbidden();
    }

    public function test_delegate_can_grant_and_revoke_own_but_not_owner_grant(): void
    {
        [$album] = $this->albumWithMedia();
        $third = User::factory()->create();

        // Le propriétaire donne accès au viewer (qui devient délégué).
        AlbumAccess::create(['album_id' => $album->id, 'user_id' => $this->viewer->id, 'granted_by' => $this->owner->id]);

        // Le délégué peut accorder à un tiers…
        $this->actingAs($this->viewer)
            ->postJson("/albums/{$album->id}/access", ['user_id' => $third->id])
            ->assertCreated();
        $this->assertTrue($album->fresh()->isAccessibleBy($third));

        // …révoquer son propre octroi…
        $this->actingAs($this->viewer)
            ->deleteJson("/albums/{$album->id}/access", ['user_id' => $third->id])
            ->assertOk();

        // …mais PAS révoquer l'accès que le propriétaire lui a donné à lui-même.
        $this->actingAs($this->viewer)
            ->deleteJson("/albums/{$album->id}/access", ['user_id' => $this->viewer->id])
            ->assertForbidden();
    }

    public function test_access_list_reports_origins(): void
    {
        [$album, $media] = $this->albumWithMedia();
        AlbumAccess::create(['album_id' => $album->id, 'user_id' => $this->viewer->id, 'granted_by' => $this->owner->id]);

        $tagged = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        $media->people()->attach($person->id);
        $tagged->update(['person_id' => $person->id]);

        $data = $this->actingAs($this->owner)->getJson("/albums/{$album->id}/access")->assertOk()->json();
        $byUser = collect($data)->keyBy('user_id');

        $this->assertSame('owner', $byUser[$this->owner->id]['origin']);
        $this->assertSame('granted', $byUser[$this->viewer->id]['origin']);
        $this->assertSame('tagged', $byUser[$tagged->id]['origin']);
    }

    public function test_shared_with_me_lists_accessible_not_owned(): void
    {
        [$publicAlbum] = $this->albumWithMedia(['is_public' => true]);
        [$privateAlbum] = $this->albumWithMedia(); // non accessible au viewer
        $ownAlbum = Album::factory()->create(['user_id' => $this->viewer->id]); // possédé → exclu

        $ids = collect($this->actingAs($this->viewer)->getJson('/albums/shared-with-me')->assertOk()->json())
            ->pluck('id');

        $this->assertContains($publicAlbum->id, $ids);
        $this->assertNotContains($privateAlbum->id, $ids);
        $this->assertNotContains($ownAlbum->id, $ids);
    }
}
