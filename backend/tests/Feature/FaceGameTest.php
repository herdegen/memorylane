<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\DetectedFace;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Vision\FaceMatcher;
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

    /** Un compte membre d'une famille et d'un groupe d'amis, une photo partagée aux deux. */
    private function twoWorlds(): array
    {
        $me = User::factory()->create();
        $family = Household::factory()->create();
        $friends = Household::factory()->friends()->create();
        $family->members()->attach($me->id);
        $friends->members()->attach($me->id);
        $media = Media::factory()->photo()->create(['user_id' => $me->id]);
        $media->households()->attach([$family->id, $friends->id]);

        return [$me, $family, $friends, $media];
    }

    public function test_inconnu_dans_un_groupe_ne_verrouille_pas_les_autres_cercles(): void
    {
        [$me, $family, $friends, $media] = $this->twoWorlds();
        $face = $this->unmatchedFace($media);

        $me->forceFill(['current_household_id' => $friends->id])->save();
        $this->actingAs($me)->postJson('/quests/answer', [
            'question_type' => 'face_identify',
            'subject_id' => $face->id,
            'answer_kind' => 'no',
            'payload' => ['reason' => 'stranger'],
        ])->assertSuccessful();

        // Le visage reste à identifier pour tout le monde…
        $this->assertSame('unmatched', $face->fresh()->status);
        $this->assertDatabaseHas('quest_answers', ['subject_id' => $face->id, 'household_id' => $friends->id]);

        // …n'est plus proposé dans le groupe d'amis…
        $inFriends = $this->actingAs($me)->getJson('/quests/faces/next')->json();
        $this->assertNotContains($face->id, array_column($inFriends['questions'], 'subject_id'));

        // …mais l'est toujours dans la famille.
        $me->forceFill(['current_household_id' => $family->id])->save();
        $inFamily = $this->actingAs($me->fresh())->getJson('/quests/faces/next')->json();
        $this->assertContains($face->id, array_column($inFamily['questions'], 'subject_id'));
    }

    public function test_des_inconnus_recents_ne_tarissent_pas_les_questions(): void
    {
        [$me, , $friends, $media] = $this->twoWorlds();
        $me->forceFill(['current_household_id' => $friends->id])->save();
        // 12 visages, sur des photos distinctes (pas d'arrière-plan) ; les
        // 10 plus récents sont dits « inconnu » dans le groupe.
        $faces = [];
        foreach (range(1, 12) as $i) {
            $photo = Media::factory()->photo()->create(['user_id' => $me->id]);
            $photo->households()->attach($friends->id);
            $face = $this->unmatchedFace($photo);
            $face->forceFill(['created_at' => now()->subMinutes(100 - $i)])->saveQuietly();
            $faces[] = $face;
        }
        foreach (array_slice($faces, 2) as $face) {
            \App\Models\QuestAnswer::create([
                'user_id' => $me->id, 'household_id' => $friends->id,
                'question_type' => 'face_identify', 'question_key' => 'face_identify:'.$face->id,
                'subject_type' => $face->getMorphClass(), 'subject_id' => $face->id,
                'answer_kind' => 'no', 'payload' => ['reason' => 'stranger'],
            ]);
        }

        $keys = array_map(fn ($c) => $c['subject_id'], app(\App\Services\QuestService::class)->candidates($me->fresh()));

        $this->assertContains($faces[0]->id, $keys);
        $this->assertContains($faces[1]->id, $keys);
        $this->assertNotContains($faces[11]->id, $keys);
    }

    public function test_pas_un_visage_vaut_pour_tout_le_monde(): void
    {
        [$me, , $friends, $media] = $this->twoWorlds();
        $face = $this->unmatchedFace($media);
        $me->forceFill(['current_household_id' => $friends->id])->save();

        $this->actingAs($me)->postJson('/quests/answer', [
            'question_type' => 'face_identify',
            'subject_id' => $face->id,
            'answer_kind' => 'no',
            'payload' => ['reason' => 'not_face'],
        ])->assertSuccessful();

        $this->assertSame('dismissed', $face->fresh()->status);
    }

    public function test_suggestions_limitees_aux_fiches_du_cercle_courant(): void
    {
        [$me, $family, $friends, $media] = $this->twoWorlds();
        $face = $this->unmatchedFace($media);
        $lea = Person::factory()->create(['name' => 'Léa Amie', 'in_family_directory' => false]);
        $lea->circles()->attach($friends->id);
        $paul = Person::factory()->create(['name' => 'Paul Cousin', 'in_family_directory' => true]);

        $this->partialMock(FaceMatcher::class, fn ($m) => $m->shouldReceive('rankedCandidates')->andReturn([
            ['person' => ['id' => $paul->id, 'name' => $paul->name], 'distance' => 0.3, 'score' => 0.9],
            ['person' => ['id' => $lea->id, 'name' => $lea->name], 'distance' => 0.4, 'score' => 0.7],
        ]));

        $me->forceFill(['current_household_id' => $friends->id])->save();
        $q = $this->actingAs($me)->getJson('/quests/faces/next')->json('questions.0');
        $this->assertSame($face->id, $q['subject_id']);
        $this->assertSame([$lea->id], array_column(array_column($q['face']['suggestions'], 'person'), 'id'));

        $me->forceFill(['current_household_id' => $family->id])->save();
        $q = $this->actingAs($me->fresh())->getJson('/quests/faces/next')->json('questions.0');
        $this->assertSame([$paul->id], array_column(array_column($q['face']['suggestions'], 'person'), 'id'));
    }

    public function test_album_inaccessible_refuse(): void
    {
        $user = User::factory()->create();
        $album = Album::factory()->create();

        $this->actingAs($user)->getJson("/quests/faces/next?album={$album->id}")->assertNotFound();
    }
}
