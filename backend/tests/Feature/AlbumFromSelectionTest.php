<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * « Créer un album à partir d'une sélection » : création d'album avec des
 * médias initiaux (galerie / fin d'upload) et endpoint « tout sélectionner »
 * qui renvoie tous les IDs du filtre courant.
 */
class AlbumFromSelectionTest extends TestCase
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

    public function test_can_create_album_with_initial_media(): void
    {
        $media = Media::factory()->count(3)->create(['user_id' => $this->user->id]);
        $mediaIds = $media->pluck('id')->all();

        $response = $this->actingAs($this->user)->postJson('/albums', [
            'name' => 'Lot du jour',
            'media_ids' => $mediaIds,
        ]);

        $response->assertStatus(201);
        $albumId = $response->json('album.id');

        foreach ($mediaIds as $id) {
            $this->assertDatabaseHas('album_media', [
                'album_id' => $albumId,
                'media_id' => $id,
            ]);
        }

        // La couverture prend le premier média de la sélection.
        $this->assertSame($mediaIds[0], Album::find($albumId)->cover_media_id);
    }

    public function test_creating_album_without_media_ids_still_works(): void
    {
        $response = $this->actingAs($this->user)->postJson('/albums', [
            'name' => 'Album vide',
        ]);

        $response->assertStatus(201);
        $this->assertSame(0, Album::find($response->json('album.id'))->media()->count());
    }

    public function test_smart_album_ignores_media_ids(): void
    {
        // Une PHOTO (type forcé) : elle ne matche pas la règle vidéo, donc son
        // éventuel rattachement viendrait forcément de media_ids — ce qu'on veut
        // justement voir ignoré pour un album intelligent.
        $media = Media::factory()->photo()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)->postJson('/albums', [
            'name' => 'Intelligent',
            'is_smart' => true,
            'smart_rules' => ['type' => 'video'],
            'media_ids' => [$media->id],
        ]);

        $response->assertStatus(201);
        // Une photo ne doit pas être rattachée de force à un album intelligent
        // dont la règle cible les vidéos.
        $this->assertDatabaseMissing('album_media', [
            'album_id' => $response->json('album.id'),
            'media_id' => $media->id,
        ]);
    }

    public function test_media_ids_endpoint_returns_all_owned_ids(): void
    {
        $mine = Media::factory()->count(4)->create(['user_id' => $this->user->id]);
        Media::factory()->count(2)->create(['user_id' => $this->otherUser->id]);

        $response = $this->actingAs($this->user)->getJson('/media/ids');

        $response->assertStatus(200);
        $ids = $response->json('ids');

        $this->assertCount(4, $ids);
        $this->assertEqualsCanonicalizing($mine->pluck('id')->all(), $ids);
    }

    public function test_media_ids_endpoint_honours_type_filter(): void
    {
        $photos = Media::factory()->count(3)->create(['user_id' => $this->user->id, 'type' => 'photo']);
        Media::factory()->count(2)->create(['user_id' => $this->user->id, 'type' => 'video']);

        $response = $this->actingAs($this->user)->getJson('/media/ids?type=photo');

        $response->assertStatus(200);
        $this->assertEqualsCanonicalizing($photos->pluck('id')->all(), $response->json('ids'));
    }

    public function test_media_ids_endpoint_excludes_source_videos(): void
    {
        $visible = Media::factory()->create(['user_id' => $this->user->id, 'is_source' => false]);
        Media::factory()->create(['user_id' => $this->user->id, 'is_source' => true]);

        $response = $this->actingAs($this->user)->getJson('/media/ids');

        $response->assertStatus(200);
        $this->assertSame([$visible->id], $response->json('ids'));
    }

    public function test_album_media_est_trie_par_date_de_prise_de_vue(): void
    {
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        $recent = Media::factory()->create(['user_id' => $this->user->id, 'taken_at' => '2023-01-01']);
        $ancien = Media::factory()->create(['user_id' => $this->user->id, 'taken_at' => '2020-01-01']);
        $milieu = Media::factory()->create(['user_id' => $this->user->id, 'taken_at' => '2021-06-01']);

        // Attaché dans un ordre NON chronologique (l'ordre d'insertion ne doit
        // plus décider du tri).
        $album->media()->attach($recent->id, ['order' => 0]);
        $album->media()->attach($ancien->id, ['order' => 1]);
        $album->media()->attach($milieu->id, ['order' => 2]);

        $ordered = $album->media()->pluck('media.id')->all();

        // Chronologique croissant : ancien → milieu → récent.
        $this->assertSame([$ancien->id, $milieu->id, $recent->id], $ordered);
    }
}
