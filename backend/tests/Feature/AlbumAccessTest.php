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

    public function test_public_flag_no_longer_opens_an_album_to_everyone(): void
    {
        // « Public » = par cercle désormais : l'ancien drapeau n'ouvre plus rien.
        [$album] = $this->albumWithMedia(['is_public' => true]);
        $this->actingAs($this->viewer)->getJson("/albums/{$album->id}")->assertForbidden();
        $this->assertFalse($album->isAccessibleBy($this->viewer));
    }

    public function test_album_shared_with_a_circle_is_open_to_its_members_only(): void
    {
        [$album] = $this->albumWithMedia();
        $circle = \App\Models\Household::factory()->create(['created_by' => $album->user_id]);
        $circle->members()->attach([$album->user_id, $this->viewer->id]);
        $album->households()->attach($circle->id, ['granted_by' => $album->user_id]);

        $this->actingAs($this->viewer)->getJson("/albums/{$album->id}")->assertOk();
        $this->assertFalse($album->isAccessibleBy(User::factory()->create()));
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

    public function test_tagged_user_sees_the_photos_of_them_but_not_the_whole_album(): void
    {
        [$album, $media] = $this->albumWithMedia();
        $other = Media::factory()->create(['user_id' => $this->owner->id]);
        $album->media()->attach($other->id);

        // Le visiteur est identifié sur UNE photo d'un album privé.
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        $media->people()->attach($person->id);
        $this->viewer->update(['person_id' => $person->id]);
        $viewer = $this->viewer->fresh();

        // Il voit cette photo-là…
        $this->assertTrue(Media::accessibleBy($viewer)->whereKey($media->id)->exists());
        $this->actingAs($viewer)->get("/media/{$media->id}/file")->assertStatus(302);

        // … mais ni l'album, ni ses autres photos, ni le droit de le partager.
        $this->assertFalse($album->fresh()->isAccessibleBy($viewer));
        $this->actingAs($viewer)->getJson("/albums/{$album->id}")->assertForbidden();
        $this->actingAs($viewer)->get("/media/{$other->id}/file")->assertForbidden();
        $this->actingAs($viewer)
            ->postJson("/albums/{$album->id}/access", ['user_id' => User::factory()->create()->id])
            ->assertForbidden();
    }

    public function test_tagging_oneself_on_a_visible_photo_does_not_open_the_album(): void
    {
        // Album d'autrui dont UNE seule photo m'est partagée via un cercle.
        [$album, $media] = $this->albumWithMedia();
        $hidden = Media::factory()->create(['user_id' => $this->owner->id]);
        $album->media()->attach($hidden->id);
        $circle = \App\Models\Household::factory()->create(['created_by' => $this->owner->id]);
        $circle->members()->attach([$this->owner->id, $this->viewer->id]);
        $media->households()->attach($circle->id, ['added_by' => $this->owner->id]);

        // Je m'identifie sur la photo que je vois : rien de plus ne s'ouvre.
        $me = Person::factory()->create(['user_id' => $this->viewer->id]);
        $this->viewer->update(['person_id' => $me->id]);
        $media->people()->attach($me->id);
        $viewer = $this->viewer->fresh();

        $this->assertFalse($album->fresh()->isAccessibleBy($viewer));
        $this->actingAs($viewer)->get("/media/{$hidden->id}/file")->assertForbidden();
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

    public function test_albums_index_includes_owned_and_shared(): void
    {
        [$publicAlbum] = $this->albumWithMedia(); // partagé avec un cercle du viewer
        $circle = \App\Models\Household::factory()->create(['created_by' => $publicAlbum->user_id]);
        $circle->members()->attach([$publicAlbum->user_id, $this->viewer->id]);
        $publicAlbum->households()->attach($circle->id, ['granted_by' => $publicAlbum->user_id]);
        [$privateAlbum] = $this->albumWithMedia();                     // non accessible au viewer
        $ownAlbum = Album::factory()->create(['user_id' => $this->viewer->id]); // le sien

        $ids = collect($this->actingAs($this->viewer)->getJson('/albums')->assertOk()->json())
            ->pluck('id');

        $this->assertContains($ownAlbum->id, $ids);      // ses albums
        $this->assertContains($publicAlbum->id, $ids);   // + partagés
        $this->assertNotContains($privateAlbum->id, $ids); // pas les inaccessibles
    }
}
