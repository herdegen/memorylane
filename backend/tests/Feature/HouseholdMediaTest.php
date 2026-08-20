<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Foyers (phase 2b) : partage de médias dans un foyer (pivot household_media)
 * et branche foyer de Media::scopeAccessibleBy.
 */
class HouseholdMediaTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $member;
    protected User $stranger;
    protected Household $household;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->household = Household::factory()->create(['created_by' => $this->owner->id]);
        $this->household->members()->attach([$this->owner->id, $this->member->id]);
    }

    public function test_partage_de_masse_ne_partage_que_mes_medias(): void
    {
        $mine = Media::factory()->create(['user_id' => $this->owner->id]);
        $foreign = Media::factory()->create(['user_id' => $this->stranger->id]);

        $response = $this->actingAs($this->owner)->postJson('/media/bulk/household', [
            'media_ids' => [$mine->id, $foreign->id],
            'household_id' => $this->household->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('skipped', 1);

        $this->assertDatabaseHas('household_media', [
            'household_id' => $this->household->id,
            'media_id' => $mine->id,
            'added_by' => $this->owner->id,
        ]);
        $this->assertDatabaseMissing('household_media', [
            'media_id' => $foreign->id,
        ]);
    }

    public function test_partage_exige_d_etre_membre_du_foyer(): void
    {
        $media = Media::factory()->create(['user_id' => $this->stranger->id]);

        $response = $this->actingAs($this->stranger)->postJson('/media/bulk/household', [
            'media_ids' => [$media->id],
            'household_id' => $this->household->id,
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('household_media', ['media_id' => $media->id]);
    }

    public function test_partage_idempotent_sur_media_deja_partage(): void
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id]);
        $this->household->media()->attach($media->id, ['added_by' => $this->owner->id]);

        $this->actingAs($this->owner)->postJson('/media/bulk/household', [
            'media_ids' => [$media->id],
            'household_id' => $this->household->id,
        ])->assertOk();

        $this->assertSame(1, $this->household->media()->count());
    }

    public function test_membre_du_foyer_voit_le_media_partage(): void
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id]);
        $this->household->media()->attach($media->id, ['added_by' => $this->owner->id]);

        $this->actingAs($this->member)
            ->getJson("/media/{$media->id}")
            ->assertOk();
    }

    public function test_non_membre_ne_voit_pas_le_media_partage(): void
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id]);
        $this->household->media()->attach($media->id, ['added_by' => $this->owner->id]);

        $this->actingAs($this->stranger)
            ->getJson("/media/{$media->id}")
            ->assertStatus(403);
    }

    public function test_media_non_partage_reste_prive_pour_les_membres(): void
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id]);

        $this->actingAs($this->member)
            ->getJson("/media/{$media->id}")
            ->assertStatus(403);
    }

    public function test_retrait_du_foyer_revoque_l_acces(): void
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id]);
        $this->household->media()->attach($media->id, ['added_by' => $this->owner->id]);

        $response = $this->actingAs($this->owner)->postJson('/media/bulk/household/remove', [
            'media_ids' => [$media->id],
            'household_id' => $this->household->id,
        ]);

        $response->assertOk()->assertJsonPath('updated', 1);
        $this->assertDatabaseMissing('household_media', ['media_id' => $media->id]);

        $this->actingAs($this->member)
            ->getJson("/media/{$media->id}")
            ->assertStatus(403);
    }

    public function test_retrait_ne_touche_pas_les_medias_d_autrui(): void
    {
        // Le membre a partagé SON média ; le créateur du foyer ne peut pas
        // le retirer en masse (seul le propriétaire gère ses partages, v1).
        $media = Media::factory()->create(['user_id' => $this->member->id]);
        $this->household->media()->attach($media->id, ['added_by' => $this->member->id]);

        $this->actingAs($this->owner)->postJson('/media/bulk/household/remove', [
            'media_ids' => [$media->id],
            'household_id' => $this->household->id,
        ])->assertOk()->assertJsonPath('updated', 0);

        $this->assertDatabaseHas('household_media', ['media_id' => $media->id]);
    }

    public function test_quitter_le_foyer_fait_perdre_l_acces(): void
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id]);
        $this->household->media()->attach($media->id, ['added_by' => $this->owner->id]);

        $this->actingAs($this->member)->postJson("/households/{$this->household->id}/leave")->assertOk();

        $this->actingAs($this->member)
            ->getJson("/media/{$media->id}")
            ->assertStatus(403);
    }

    public function test_la_page_foyer_liste_les_medias_partages(): void
    {
        $media = Media::factory()->create(['user_id' => $this->owner->id]);
        $this->household->media()->attach($media->id, ['added_by' => $this->owner->id]);

        $this->actingAs($this->member)
            ->get("/households/{$this->household->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('media', 1)
                ->where('media.0.id', $media->id));
    }

    public function test_le_fichier_du_media_partage_est_servi_au_membre(): void
    {
        Storage::fake(config('filesystems.default'));

        $media = Media::factory()->create([
            'user_id' => $this->owner->id,
            'file_path' => 'photos/foyer.jpg',
        ]);
        Storage::disk(config('filesystems.default'))->put('photos/foyer.jpg', 'x');

        $this->household->media()->attach($media->id, ['added_by' => $this->owner->id]);

        // La route fichier applique MediaPolicy::view puis 302 vers une
        // présignée S3 courte : le membre du foyer doit passer.
        $this->actingAs($this->member)
            ->get("/media/{$media->id}/file")
            ->assertRedirect();

        $this->actingAs($this->stranger)
            ->get("/media/{$media->id}/file")
            ->assertStatus(403);
    }
}
