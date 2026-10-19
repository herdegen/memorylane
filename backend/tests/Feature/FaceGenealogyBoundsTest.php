<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Services\Vision\FacePlausibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Naissances inconnues bornées par l'arbre : un arrière-arrière-grand-parent
 * n'est pas proposé sur une photo de 2020, un descendant pas encore né non plus.
 */
class FaceGenealogyBoundsTest extends TestCase
{
    use RefreshDatabase;

    public function test_ancetres_sans_date_ecartes_d_une_photo_recente(): void
    {
        $gggp = Person::factory()->create(['name' => 'Arrière-arrière', 'birth_date' => null, 'death_date' => null]);
        $ggp = Person::factory()->create(['name' => 'Arrière', 'birth_date' => null, 'death_date' => null, 'father_id' => $gggp->id]);
        $gp = Person::factory()->create(['name' => 'Grand-parent', 'birth_date' => null, 'death_date' => null, 'father_id' => $ggp->id]);
        $parent = Person::factory()->create(['name' => 'Parent', 'birth_date' => null, 'death_date' => null, 'father_id' => $gp->id]);
        Person::factory()->create(['name' => 'Moi', 'birth_date' => '1988-05-01', 'father_id' => $parent->id]);

        $pl = new FacePlausibility();
        $bounds = $pl->genealogyBounds();

        $this->assertSame(1970, $bounds[$parent->id]['latest']);
        $this->assertSame(1916, $bounds[$gggp->id]['latest']);

        // 2020 : l'arrière-arrière-grand-parent aurait plus de 100 ans
        $this->assertTrue($pl->genealogyImplausible($gggp->fresh(), 2020));
        $this->assertFalse($pl->genealogyImplausible($ggp->fresh(), 2020));
        // Sur une photo de 1950, il reste possible
        $this->assertFalse($pl->genealogyImplausible($gggp->fresh(), 1950));

        $photo = Media::factory()->photo()->create(['taken_at' => '2020-06-20']);
        $this->assertTrue($pl->unlikelySuggestion($gggp->fresh(), $photo, null));
        $this->assertSame('generation', $pl->doubts($gggp->fresh(), $photo)[0]['code']);
    }

    public function test_descendant_sans_date_pas_encore_ne(): void
    {
        $parent = Person::factory()->create(['birth_date' => '2000-01-01']);
        $child = Person::factory()->create(['birth_date' => null, 'death_date' => null, 'mother_id' => $parent->id]);

        $pl = new FacePlausibility();

        $this->assertSame(2014, $pl->genealogyBounds()[$child->id]['earliest']); // parent né en 2000 + 14 ans
        $this->assertTrue($pl->genealogyImplausible($child->fresh(), 1995));
        $this->assertFalse($pl->genealogyImplausible($child->fresh(), 2024));
    }
}
