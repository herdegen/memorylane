<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Jeu « Qui est-ce ? » plein écran (téléphone) : page, lot de visages,
 * limitation à un album, exclusion des visages déjà vus dans la partie.
 */
class FaceGameTest extends TestCase
{
    use RefreshDatabase;

    private function unmatchedFace(Media $media)
    {
        return $media->detectedFaces()->create([
            'bounding_box' => ['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20],
            'status' => 'unmatched',
            'provider' => 'faceapi',
        ]);
    }

    public function test_page_du_jeu(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/quests/faces')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Quests/Faces', false)->where('album', null));
    }

    public function test_lot_de_visages_et_exclusion_des_deja_vus(): void
    {
        $user = User::factory()->create();
        $media = Media::factory()->photo()->create(['user_id' => $user->id]);
        $a = $this->unmatchedFace($media);
        $b = $this->unmatchedFace($media);
        // Un autre compte : invisible
        $this->unmatchedFace(Media::factory()->photo()->create());

        $first = $this->actingAs($user)->getJson('/quests/faces/next')->assertOk()->json();
        $this->assertSame(2, $first['total']);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($first['questions'], 'subject_id'));
        $this->assertSame('face_identify', $first['questions'][0]['type']);
        $this->assertArrayHasKey('crop_url', $first['questions'][0]['face']);

        $second = $this->actingAs($user)->getJson('/quests/faces/next?exclude[]='.$a->id)->assertOk()->json();
        $this->assertSame([$b->id], array_column($second['questions'], 'subject_id'));
    }

    public function test_lot_limite_a_un_album(): void
    {
        $user = User::factory()->create();
        $inAlbum = Media::factory()->photo()->create(['user_id' => $user->id]);
        $outside = Media::factory()->photo()->create(['user_id' => $user->id]);
        $album = Album::factory()->create(['user_id' => $user->id]);
        $album->media()->attach($inAlbum->id);
        $face = $this->unmatchedFace($inAlbum);
        $this->unmatchedFace($outside);

        $data = $this->actingAs($user)->getJson("/quests/faces/next?album={$album->id}")->assertOk()->json();

        $this->assertSame([$face->id], array_column($data['questions'], 'subject_id'));
        $this->assertSame(1, $data['total']);

        $this->actingAs($user)->get("/quests/faces?album={$album->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Quests/Faces', false)->where('album.id', $album->id));
    }

    public function test_album_inaccessible_refuse(): void
    {
        $user = User::factory()->create();
        $album = Album::factory()->create();

        $this->actingAs($user)->getJson("/quests/faces/next?album={$album->id}")->assertNotFound();
    }
}
