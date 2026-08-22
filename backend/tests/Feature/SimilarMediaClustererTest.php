<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Services\SimilarMediaClusterer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Clustering des quasi-doublons (issue #42, tranche 3) : union-find sur la
 * distance de Hamming des dHash, matérialisé dans media.similar_group_id.
 */
class SimilarMediaClustererTest extends TestCase
{
    use RefreshDatabase;

    private function photo(User $owner, string $phash, array $extra = []): Media
    {
        return Media::factory()->photo()->create(array_merge([
            'user_id' => $owner->id,
            'perceptual_hash' => $phash,
        ], $extra));
    }

    private function cluster(int $threshold = SimilarMediaClusterer::DEFAULT_THRESHOLD): array
    {
        return app(SimilarMediaClusterer::class)->cluster($threshold);
    }

    public function test_groupe_les_photos_proches_et_ignore_les_lointaines(): void
    {
        $user = User::factory()->create();

        // Distance 1 entre a et b ; f est à 32+ bits de tout le monde.
        $a = $this->photo($user, '0000000000000000');
        $b = $this->photo($user, '0000000000000001');
        $far = $this->photo($user, 'ffffffff00000000');

        $result = $this->cluster();

        $this->assertSame(1, $result['groups']);
        $this->assertSame(2, $result['grouped']);
        $this->assertNotNull($a->fresh()->similar_group_id);
        $this->assertSame($a->fresh()->similar_group_id, $b->fresh()->similar_group_id);
        $this->assertNull($far->fresh()->similar_group_id);
    }

    public function test_fermeture_transitive_en_un_seul_groupe(): void
    {
        $user = User::factory()->create();

        // a~b = 4 bits, b~c = 4 bits, mais a~c = 8+4... construit pour que
        // a et c soient au-delà du seuil 4 : le groupe doit quand même être unique.
        $a = $this->photo($user, '000000000000000f'); // 4 bits vs b
        $b = $this->photo($user, '0000000000000000');
        $c = $this->photo($user, '00000000000000f0'); // 4 bits vs b, 8 vs a

        $this->cluster(4);

        $groups = collect([$a, $b, $c])->map(fn ($m) => $m->fresh()->similar_group_id)->unique();
        $this->assertCount(1, $groups);
        $this->assertNotNull($groups->first());
    }

    public function test_le_seuil_est_respecte(): void
    {
        $user = User::factory()->create();

        // Distance exacte de 8 bits.
        $a = $this->photo($user, '00000000000000ff');
        $b = $this->photo($user, '0000000000000000');

        $this->cluster(7);
        $this->assertNull($a->fresh()->similar_group_id, 'À distance 8, le seuil 7 ne doit pas grouper');

        $this->cluster(8);
        $this->assertNotNull($a->fresh()->similar_group_id, 'À distance 8, le seuil 8 doit grouper');
        $this->assertSame($a->fresh()->similar_group_id, $b->fresh()->similar_group_id);
    }

    public function test_ne_melange_pas_les_proprietaires(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        // Hashes identiques mais propriétaires différents → pas de groupe.
        $mine = $this->photo($user, 'abcdef0123456789');
        $theirs = $this->photo($other, 'abcdef0123456789');

        $result = $this->cluster();

        $this->assertSame(0, $result['groups']);
        $this->assertNull($mine->fresh()->similar_group_id);
        $this->assertNull($theirs->fresh()->similar_group_id);
    }

    public function test_les_doublons_binaires_exacts_ne_creent_pas_de_lien(): void
    {
        $user = User::factory()->create();

        // Même content_hash (doublon exact, écran « Doublons ») : dHash à
        // distance 0 mais AUCUN lien de quasi-doublon.
        $a = $this->photo($user, '1111111111111111', ['content_hash' => 'same-sha']);
        $b = $this->photo($user, '1111111111111111', ['content_hash' => 'same-sha']);

        $result = $this->cluster();

        $this->assertSame(0, $result['groups']);
        $this->assertNull($a->fresh()->similar_group_id);
        $this->assertNull($b->fresh()->similar_group_id);
    }

    public function test_un_recalcul_dissout_les_groupes_perimes(): void
    {
        $user = User::factory()->create();

        $a = $this->photo($user, '0000000000000000');
        $b = $this->photo($user, '0000000000000001');

        $this->cluster();
        $this->assertNotNull($a->fresh()->similar_group_id);

        // b passe à la corbeille : le groupe n'a plus de raison d'être.
        $b->delete();
        $result = $this->cluster();

        $this->assertSame(0, $result['groups']);
        $this->assertNull($a->fresh()->similar_group_id);
    }

    public function test_id_de_groupe_stable_egal_au_plus_petit_uuid(): void
    {
        $user = User::factory()->create();

        $a = $this->photo($user, '0000000000000000');
        $b = $this->photo($user, '0000000000000001');

        $this->cluster();
        $first = $a->fresh()->similar_group_id;

        $this->cluster();

        $this->assertSame($first, $a->fresh()->similar_group_id, 'Le recalcul ne doit pas changer l\'id d\'un groupe inchangé');
        $this->assertSame(min($a->id, $b->id), $first);
    }

    public function test_les_photos_sans_empreinte_sont_ignorees(): void
    {
        $user = User::factory()->create();

        $hashed = $this->photo($user, '0000000000000000');
        $unhashed = Media::factory()->photo()->create(['user_id' => $user->id, 'perceptual_hash' => null]);

        $result = $this->cluster();

        $this->assertSame(1, $result['photos']);
        $this->assertNull($unhashed->fresh()->similar_group_id);
        $this->assertNull($hashed->fresh()->similar_group_id);
    }
}
