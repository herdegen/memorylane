<?php

namespace Tests\Feature;

use App\Filament\Resources\SimilarMediaResource\Pages\ListSimilarMedia;
use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Écran backoffice « Quasi-doublons » (issue #42, tranche 3).
 */
class SimilarMediaAdminTest extends TestCase
{
    use RefreshDatabase;

    /** Photo rattachée à un groupe de similarité imposé. */
    private function photoInGroup(User $owner, ?string $group, array $extra = []): Media
    {
        return Media::factory()->photo()->create(array_merge([
            'user_id' => $owner->id,
            'similar_group_id' => $group,
        ], $extra));
    }

    public function test_admin_peut_voir_la_page_quasi_doublons(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/similar-media');

        $response->assertStatus(200);
    }

    public function test_ne_liste_que_les_groupes_avec_un_jumeau_vivant(): void
    {
        $admin = User::factory()->admin()->create();

        // Groupe vivant complet → visible.
        $a = $this->photoInGroup($admin, 'group-1');
        $b = $this->photoInGroup($admin, 'group-1');

        // Photo hors groupe → exclue.
        $lone = $this->photoInGroup($admin, null);

        // Groupe dont le jumeau est à la corbeille → le survivant disparaît.
        $kept = $this->photoInGroup($admin, 'group-2');
        $trashed = $this->photoInGroup($admin, 'group-2');
        $trashed->delete();

        Livewire::actingAs($admin)
            ->test(ListSimilarMedia::class)
            ->assertCanSeeTableRecords([$a, $b])
            ->assertCanNotSeeTableRecords([$lone, $kept]);
    }

    public function test_keep_best_privilegie_les_attaches_puis_la_resolution(): void
    {
        $admin = User::factory()->admin()->create();

        // Sans attache : la plus haute résolution gagne, malgré son âge.
        $small = $this->photoInGroup($admin, 'group-R', [
            'width' => 800, 'height' => 600, 'size' => 100, 'uploaded_at' => now()->subDays(3),
        ]);
        $big = $this->photoInGroup($admin, 'group-R', [
            'width' => 4000, 'height' => 3000, 'size' => 100, 'uploaded_at' => now(),
        ]);

        // Avec attache : l'album l'emporte sur la résolution.
        $inAlbum = $this->photoInGroup($admin, 'group-A', [
            'width' => 800, 'height' => 600, 'size' => 100, 'uploaded_at' => now(),
        ]);
        $sharper = $this->photoInGroup($admin, 'group-A', [
            'width' => 4000, 'height' => 3000, 'size' => 100, 'uploaded_at' => now(),
        ]);
        $album = Album::factory()->create(['user_id' => $admin->id]);
        $inAlbum->albums()->attach($album->id);

        Livewire::actingAs($admin)
            ->test(ListSimilarMedia::class)
            ->callTableBulkAction('keepBest', [$small, $big, $inAlbum, $sharper]);

        $this->assertNull($big->fresh()->deleted_at, 'La plus haute résolution doit être conservée');
        $this->assertNotNull($small->fresh()->deleted_at);
        $this->assertNull($inAlbum->fresh()->deleted_at, 'La photo rangée en album doit être conservée');
        $this->assertNotNull($sharper->fresh()->deleted_at);
    }

    public function test_corbeille_soft_delete_la_selection(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->photoInGroup($admin, 'group-T');
        $b = $this->photoInGroup($admin, 'group-T');

        Livewire::actingAs($admin)
            ->test(ListSimilarMedia::class)
            ->callTableBulkAction('trash', [$a, $b]);

        $this->assertNotNull($a->fresh()->deleted_at);
        $this->assertNotNull($b->fresh()->deleted_at);
    }

    public function test_recalculer_regroupe_depuis_les_empreintes(): void
    {
        $admin = User::factory()->admin()->create();

        // Deux photos proches (distance 1) sans groupe : le bouton
        // « Recalculer » doit les regrouper et les faire apparaître.
        $a = Media::factory()->photo()->create(['user_id' => $admin->id, 'perceptual_hash' => '0000000000000000']);
        $b = Media::factory()->photo()->create(['user_id' => $admin->id, 'perceptual_hash' => '0000000000000001']);

        Livewire::actingAs($admin)
            ->test(ListSimilarMedia::class)
            ->callAction('recluster', ['threshold' => 8]);

        $this->assertNotNull($a->fresh()->similar_group_id);
        $this->assertSame($a->fresh()->similar_group_id, $b->fresh()->similar_group_id);
    }
}
