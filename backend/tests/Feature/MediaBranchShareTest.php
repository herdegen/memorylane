<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\BranchShare;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Partage vivant d'un média ou d'un album avec une branche : la vidéo de
 * Noël est pour tous les descendants de Mamie (et leurs conjoints), présents
 * ou non.
 *
 * Mamie → Claude (ex-conjoint : Xavier, union terminée) → Gaspard (conjointe
 * actuelle : Sophie). Olivier n'est pas de la branche. Matthieu partage.
 */
class MediaBranchShareTest extends TestCase
{
    use RefreshDatabase;

    private User $matthieu;

    private Household $famille;

    private array $p = [];

    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->matthieu = User::factory()->create();
        $this->famille = Household::factory()->create(['name' => 'Famille', 'created_by' => $this->matthieu->id]);
        $this->famille->members()->attach($this->matthieu->id);
        $this->matthieu->forceFill(['current_household_id' => $this->famille->id])->save();

        foreach (['mamie' => null, 'claude' => 'mamie', 'gaspard' => 'claude', 'sophie' => null, 'xavier' => null, 'olivier' => null] as $key => $parent) {
            $this->p[$key] = Person::factory()->create([
                'user_id' => $this->matthieu->id, 'name' => ucfirst($key),
                'mother_id' => $parent ? $this->p[$parent]->id : null,
            ]);
            $this->famille->people()->syncWithoutDetaching([$this->p[$key]->id]);
            if ($key !== 'mamie') {
                $this->u[$key] = User::factory()->create(['person_id' => $this->p[$key]->id, 'name' => ucfirst($key)]);
            }
        }
        PersonRelationship::create(['person1_id' => $this->p['gaspard']->id, 'person2_id' => $this->p['sophie']->id, 'type' => 'spouse']);
        PersonRelationship::create(['person1_id' => $this->p['claude']->id, 'person2_id' => $this->p['xavier']->id, 'type' => 'spouse', 'end_date' => '2001-01-01']);
    }

    private function sees(string $who, Media $media): bool
    {
        return Media::accessibleBy($this->u[$who]->fresh())->whereKey($media->id)->exists();
    }

    public function test_la_video_de_noel_est_vue_par_toute_la_branche_et_les_conjoints(): void
    {
        $video = Media::factory()->video()->create(['user_id' => $this->matthieu->id]);
        $this->assertFalse($this->sees('gaspard', $video));

        $this->actingAs($this->matthieu)->postJson('/media/bulk/branch', [
            'media_ids' => [$video->id], 'person_id' => $this->p['mamie']->id, 'include_spouses' => true,
        ])->assertOk()->assertJsonPath('updated', 1);

        $this->assertTrue($this->sees('claude', $video));
        $this->assertTrue($this->sees('gaspard', $video));
        $this->assertTrue($this->sees('sophie', $video), 'la conjointe actuelle');
        $this->assertFalse($this->sees('xavier', $video), 'un ex-conjoint');
        $this->assertFalse($this->sees('olivier', $video), 'hors de la branche');
        $this->actingAs($this->u['gaspard'])->get("/media/{$video->id}")->assertOk();
    }

    public function test_sans_les_conjoints(): void
    {
        $video = Media::factory()->video()->create(['user_id' => $this->matthieu->id]);
        $this->actingAs($this->matthieu)->postJson('/media/bulk/branch', [
            'media_ids' => [$video->id], 'person_id' => $this->p['mamie']->id, 'include_spouses' => false,
        ])->assertOk();

        $this->assertTrue($this->sees('gaspard', $video));
        $this->assertFalse($this->sees('sophie', $video));
    }

    public function test_partage_vivant_un_arriere_petit_enfant_ajoute_plus_tard_y_a_acces(): void
    {
        $video = Media::factory()->video()->create(['user_id' => $this->matthieu->id]);
        $this->actingAs($this->matthieu)->postJson('/media/bulk/branch', ['media_ids' => [$video->id], 'person_id' => $this->p['mamie']->id])->assertOk();

        $bebe = Person::factory()->create(['user_id' => $this->matthieu->id, 'father_id' => $this->p['gaspard']->id]);
        $this->u['bebe'] = User::factory()->create(['person_id' => $bebe->id]);

        $this->assertTrue($this->sees('bebe', $video));
    }

    public function test_seuls_mes_medias_sont_partages_et_le_retrait_coupe_l_acces(): void
    {
        $mine = Media::factory()->photo()->create(['user_id' => $this->matthieu->id]);
        $theirs = Media::factory()->photo()->create(['user_id' => $this->u['olivier']->id]);
        $this->actingAs($this->matthieu)->postJson('/media/bulk/branch', [
            'media_ids' => [$mine->id, $theirs->id], 'person_id' => $this->p['mamie']->id,
        ])->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('skipped', 1);
        $this->assertFalse($this->sees('gaspard', $theirs));

        $share = BranchShare::where('shareable_id', $mine->id)->firstOrFail();
        $this->actingAs($this->u['gaspard'])->deleteJson("/branch-shares/{$share->id}")->assertForbidden();
        $this->actingAs($this->matthieu)->get("/media/{$mine->id}")
            ->assertInertia(fn ($page) => $page->where('branchShares.0.person.name', 'Mamie'));
        $this->actingAs($this->matthieu)->deleteJson("/branch-shares/{$share->id}")->assertOk();
        $this->assertFalse($this->sees('gaspard', $mine));
    }

    public function test_un_album_entier_partage_avec_la_branche(): void
    {
        $album = Album::factory()->create(['user_id' => $this->matthieu->id]);
        $photo = Media::factory()->photo()->create(['user_id' => $this->matthieu->id]);
        $album->media()->attach($photo->id, ['order' => 1]);

        $this->actingAs($this->u['gaspard'])->postJson("/albums/{$album->id}/branches", ['person_id' => $this->p['mamie']->id])->assertForbidden();
        $this->actingAs($this->matthieu)->postJson("/albums/{$album->id}/branches", ['person_id' => $this->p['mamie']->id])
            ->assertCreated()->assertJsonPath('shares.0.person.name', 'Mamie');

        $this->actingAs($this->u['gaspard'])->get("/albums/{$album->id}")->assertOk();
        $this->assertTrue($this->sees('gaspard', $photo));
        $this->assertTrue(Album::accessibleBy($this->u['sophie'])->whereKey($album->id)->exists());
        $this->actingAs($this->u['olivier'])->get("/albums/{$album->id}")->assertForbidden();
    }

    public function test_apercu_de_la_branche(): void
    {
        $this->actingAs($this->matthieu)->getJson('/branch-shares/preview?person_id='.$this->p['mamie']->id.'&include_spouses=1')
            ->assertOk()->assertJsonPath('people', 4)->assertJsonPath('accounts', 3);
        $this->actingAs($this->matthieu)->getJson('/branch-shares/preview?person_id='.$this->p['mamie']->id.'&include_spouses=0')
            ->assertOk()->assertJsonPath('people', 3)->assertJsonPath('accounts', 2);
    }
}
