<?php

namespace Tests\Feature;

use App\Filament\Resources\DuplicateResource\Pages\ListDuplicates;
use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Écran backoffice « Doublons » (issue #42, tranche 1 — doublons exacts).
 */
class DuplicateAdminTest extends TestCase
{
    use RefreshDatabase;

    /** Photo avec un content_hash imposé (pour former des groupes de doublons). */
    private function photoWithHash(User $owner, string $hash, array $extra = []): Media
    {
        return Media::factory()->photo()->create(array_merge([
            'user_id' => $owner->id,
            'content_hash' => $hash,
        ], $extra));
    }

    public function test_admin_peut_voir_la_page_doublons(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get('/admin/duplicates');

        $response->assertStatus(200);
    }

    public function test_ne_liste_que_les_doublons_exacts_du_meme_proprietaire(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->create();

        // Groupe de doublons du propriétaire courant.
        $dupA = $this->photoWithHash($admin, 'hash-A');
        $dupB = $this->photoWithHash($admin, 'hash-A');

        // Photo unique (pas de jumeau) → exclue.
        $lone = $this->photoWithHash($admin, 'hash-unique');

        // Même hash mais autre propriétaire, sans jumeau chez lui → exclue
        // (le scope est par utilisateur).
        $stranger = $this->photoWithHash($other, 'hash-A');

        Livewire::actingAs($admin)
            ->test(ListDuplicates::class)
            ->assertCanSeeTableRecords([$dupA, $dupB])
            ->assertCanNotSeeTableRecords([$lone, $stranger]);
    }

    public function test_un_jumeau_a_la_corbeille_ne_compte_pas(): void
    {
        $admin = User::factory()->admin()->create();

        $kept = $this->photoWithHash($admin, 'hash-T');
        $trashed = $this->photoWithHash($admin, 'hash-T');
        $trashed->delete(); // soft delete : ce n'est plus un jumeau vivant

        Livewire::actingAs($admin)
            ->test(ListDuplicates::class)
            ->assertCanNotSeeTableRecords([$kept]);
    }

    public function test_keep_best_conserve_la_meilleure_et_corbeille_le_reste(): void
    {
        $admin = User::factory()->admin()->create();

        // Trois copies identiques. La plus ancienne est m1, mais m2 est rangée
        // dans un album → elle porte plus d'attaches et doit être conservée.
        $m1 = $this->photoWithHash($admin, 'hash-K', ['uploaded_at' => now()->subDays(3)]);
        $m2 = $this->photoWithHash($admin, 'hash-K', ['uploaded_at' => now()->subDay()]);
        $m3 = $this->photoWithHash($admin, 'hash-K', ['uploaded_at' => now()]);

        $album = Album::factory()->create(['user_id' => $admin->id]);
        $m2->albums()->attach($album->id);

        Livewire::actingAs($admin)
            ->test(ListDuplicates::class)
            ->callTableBulkAction('keepBest', [$m1, $m2, $m3]);

        $this->assertNull($m2->fresh()->deleted_at, 'La copie rangée en album doit être conservée');
        $this->assertNotNull($m1->fresh()->deleted_at);
        $this->assertNotNull($m3->fresh()->deleted_at);
    }

    public function test_keep_best_tie_break_garde_la_plus_ancienne(): void
    {
        $admin = User::factory()->admin()->create();

        // À égalité d'attaches (aucune), on garde la plus ancienne (l'originale).
        $old = $this->photoWithHash($admin, 'hash-O', ['uploaded_at' => now()->subDays(5)]);
        $recent = $this->photoWithHash($admin, 'hash-O', ['uploaded_at' => now()]);

        Livewire::actingAs($admin)
            ->test(ListDuplicates::class)
            ->callTableBulkAction('keepBest', [$old, $recent]);

        $this->assertNull($old->fresh()->deleted_at);
        $this->assertNotNull($recent->fresh()->deleted_at);
    }

    public function test_corbeille_soft_delete_la_selection(): void
    {
        $admin = User::factory()->admin()->create();
        $a = $this->photoWithHash($admin, 'hash-C');
        $b = $this->photoWithHash($admin, 'hash-C');

        Livewire::actingAs($admin)
            ->test(ListDuplicates::class)
            ->callTableBulkAction('trash', [$a, $b]);

        // Soft delete : encore présents en base (withTrashed), fichiers conservés.
        $this->assertNotNull($a->fresh()->deleted_at);
        $this->assertNotNull($b->fresh()->deleted_at);
    }

    public function test_suppression_definitive_purge_et_force_delete(): void
    {
        // S3Service mocké : delete() no-op, aucune conversion → getTemporaryUrl
        // n'est pas appelé au rendu.
        $this->mock(S3Service::class, function ($mock) {
            $mock->shouldReceive('delete')->andReturnTrue();
        });

        $admin = User::factory()->admin()->create();
        $a = $this->photoWithHash($admin, 'hash-F');
        $b = $this->photoWithHash($admin, 'hash-F');

        Livewire::actingAs($admin)
            ->test(ListDuplicates::class)
            ->callTableBulkAction('forceDelete', [$a, $b]);

        // Force delete : plus aucune trace, même avec les soft-deleted.
        $this->assertSame(0, Media::withTrashed()->whereIn('id', [$a->id, $b->id])->count());
    }
}
