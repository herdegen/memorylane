<?php

namespace Tests\Feature;

use App\Models\DetectedFace;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cercles famille / amis : visibilité des fiches (Person::scopeVisibleTo).
 *
 * Distribution :
 *  - $family   : membre du cercle familial F uniquement
 *  - $friend   : membre du cercle d'amis A uniquement
 *  - $both     : membre de F et de A
 *  - $stranger : membre du cercle d'amis B uniquement
 *  - $loner    : aucun cercle (installation familiale → voit la famille)
 * Fiches : $famP (répertoire familial), $friendP (cercle A), $strangerP (cercle B).
 */
class CircleVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private User $family;
    private User $friend;
    private User $both;
    private User $stranger;
    private User $loner;
    private User $admin;
    private Household $F;
    private Household $A;
    private Household $B;
    private Person $famP;
    private Person $friendP;
    private Person $strangerP;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->family, $this->friend, $this->both, $this->stranger, $this->loner] = User::factory()->count(5)->create()->all();
        $this->admin = User::factory()->admin()->create();

        $this->F = Household::factory()->create(['created_by' => $this->admin->id]);
        $this->A = Household::factory()->friends()->create(['created_by' => $this->admin->id]);
        $this->B = Household::factory()->friends()->create(['created_by' => $this->admin->id]);
        $this->F->members()->attach([$this->family->id, $this->both->id]);
        $this->A->members()->attach([$this->friend->id, $this->both->id]);
        $this->B->members()->attach([$this->stranger->id]);

        $this->famP = Person::factory()->create(['user_id' => $this->admin->id, 'name' => 'Mamie Famille']);
        $this->friendP = $this->circlePerson($this->A, 'Léa Copine');
        $this->strangerP = $this->circlePerson($this->B, 'Inconnu Voile');
    }

    private function circlePerson(Household $circle, string $name): Person
    {
        $p = Person::factory()->create(['user_id' => $this->admin->id, 'name' => $name, 'in_family_directory' => false]);
        $p->circles()->attach($circle->id);

        return $p;
    }

    /** Matrice viewer × fiche sur la fiche publique (404 = n'existe pas pour lui). */
    public function test_matrice_de_visibilite_des_fiches(): void
    {
        $expect = [
            'family' => [true, false, false],
            'friend' => [false, true, false],
            'both' => [true, true, false],
            'stranger' => [false, false, true],
            'loner' => [true, false, false],
            'admin' => [true, true, true],
        ];

        foreach ($expect as $viewer => [$fam, $fr, $st]) {
            foreach ([[$this->famP, $fam], [$this->friendP, $fr], [$this->strangerP, $st]] as [$person, $visible]) {
                $this->actingAs($this->$viewer)
                    ->getJson("/people/{$person->id}/timeline")
                    ->assertStatus($visible ? 200 : 404);
            }
        }
    }

    public function test_avatars_et_parente_suivent_la_meme_regle(): void
    {
        $this->actingAs($this->family)->get("/people/{$this->friendP->id}/face-avatar")->assertNotFound();
        $this->actingAs($this->family)->get("/people/{$this->friendP->id}/avatar-image")->assertNotFound();
        $this->actingAs($this->family)->getJson("/people/{$this->friendP->id}/kinship")->assertNotFound();
    }

    public function test_installation_amis_sans_cercle_ne_voit_pas_la_famille(): void
    {
        config(['memorylane.default_mode' => 'friends']);

        $this->actingAs($this->loner)->getJson("/people/{$this->famP->id}/timeline")->assertNotFound();
    }

    public function test_on_voit_toujours_sa_fiche_et_celles_qu_on_a_creees(): void
    {
        $self = $this->circlePerson($this->B, 'Moi');
        $this->friend->forceFill(['person_id' => $self->id])->save();
        $mine = Person::factory()->create(['user_id' => $this->friend->id, 'in_family_directory' => false]);

        $this->actingAs($this->friend)->getJson("/people/{$self->id}/timeline")->assertOk();
        $this->actingAs($this->friend)->getJson("/people/{$mine->id}/timeline")->assertOk();
    }

    public function test_liste_des_personnes_suit_le_cercle_courant(): void
    {
        $ids = fn (User $u) => collect($this->actingAs($u)->getJson('/people')->assertOk()->json())->pluck('id');

        $this->assertTrue($ids($this->family)->contains($this->famP->id));
        $this->assertFalse($ids($this->family)->contains($this->friendP->id));

        $this->assertTrue($ids($this->friend)->contains($this->friendP->id));
        $this->assertFalse($ids($this->friend)->contains($this->famP->id));
        $this->assertFalse($ids($this->friend)->contains($this->strangerP->id));

        // Membre des deux : le cercle courant décide de ce qui est listé.
        $this->both->forceFill(['current_household_id' => $this->A->id])->save();
        $this->assertTrue($ids($this->both->fresh())->contains($this->friendP->id));
        $this->assertFalse($ids($this->both->fresh())->contains($this->famP->id));

        $this->both->forceFill(['current_household_id' => $this->F->id])->save();
        $this->assertTrue($ids($this->both->fresh())->contains($this->famP->id));
        $this->assertFalse($ids($this->both->fresh())->contains($this->friendP->id));
    }

    public function test_arbre_reserve_au_repertoire_familial(): void
    {
        $this->actingAs($this->friend)->getJson('/family-tree/data')->assertOk()->assertExactJson([]);

        $ids = collect($this->actingAs($this->family)->getJson('/family-tree/data')->json())->pluck('id');
        $this->assertTrue($ids->contains($this->famP->id));
        $this->assertFalse($ids->contains($this->friendP->id));
    }

    public function test_arbre_ne_reference_pas_un_parent_invisible(): void
    {
        // Fiche familiale dont le père est une fiche d'un cercle d'amis.
        $child = Person::factory()->create(['user_id' => $this->admin->id, 'father_id' => $this->friendP->id]);

        $node = collect($this->actingAs($this->family)->getJson('/family-tree/data')->json())->firstWhere('id', $child->id);
        $this->assertNull($node['rels']['father']);
    }

    public function test_fiche_famille_masque_un_parent_d_un_autre_cercle(): void
    {
        $child = Person::factory()->create(['user_id' => $this->admin->id, 'father_id' => $this->friendP->id]);

        $this->actingAs($this->family)->get("/people/{$child->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Show', false)->where('father', null));
    }

    public function test_impossible_de_relier_une_fiche_invisible(): void
    {
        $own = Person::factory()->create(['user_id' => $this->family->id]);

        $this->actingAs($this->family)
            ->postJson("/people/{$own->id}/parent", ['parent_id' => $this->friendP->id, 'type' => 'father'])
            ->assertUnprocessable()->assertJsonValidationErrors('parent_id');

        $this->actingAs($this->family)
            ->postJson("/people/{$own->id}/spouse", ['spouse_id' => $this->strangerP->id])
            ->assertUnprocessable()->assertJsonValidationErrors('spouse_id');
    }

    public function test_impossible_d_identifier_un_visage_avec_une_fiche_invisible(): void
    {
        $media = Media::factory()->create(['user_id' => $this->family->id, 'type' => 'photo']);
        $face = DetectedFace::create(['media_id' => $media->id, 'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 10, 'height' => 10], 'status' => 'unmatched']);

        $this->actingAs($this->family)
            ->postJson("/vision/faces/{$face->id}/match", ['person_id' => $this->friendP->id])
            ->assertUnprocessable();
    }

    public function test_visage_identifie_dans_un_autre_cercle_masque_et_verrouille(): void
    {
        $media = Media::factory()->create(['user_id' => $this->family->id, 'type' => 'photo']);
        $face = DetectedFace::create([
            'media_id' => $media->id, 'person_id' => $this->friendP->id, 'status' => 'matched',
            'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 10, 'height' => 10],
        ]);
        $media->people()->attach([$this->friendP->id, $this->famP->id]);

        $faces = $this->actingAs($this->family)->getJson("/vision/media/{$media->id}/faces")->assertOk()->json();
        $this->assertNull($faces[0]['person']);
        $this->assertTrue($faces[0]['person_hidden']);

        $this->actingAs($this->family)->get("/media/{$media->id}")->assertOk()
            ->assertInertia(fn ($page) => $page->component('Media/Show', false)
                ->has('media.people', 1)
                ->where('media.people.0.id', $this->famP->id));

        $this->actingAs($this->family)->postJson("/vision/faces/{$face->id}/reset")->assertForbidden();
        $this->actingAs($this->family)->postJson("/vision/faces/{$face->id}/match", ['person_id' => $this->famP->id])->assertForbidden();
    }

    public function test_creer_une_fiche_depuis_un_cercle_d_amis_la_rattache(): void
    {
        $this->actingAs($this->friend)->postJson('/people', ['name' => 'Nouveau Pote'])->assertCreated();

        $p = Person::where('name', 'Nouveau Pote')->firstOrFail();
        $this->assertFalse($p->in_family_directory);
        $this->assertTrue($p->circles()->whereKey($this->A->id)->exists());

        // … et elle reste invisible côté famille.
        $this->actingAs($this->family)->getJson("/people/{$p->id}/timeline")->assertNotFound();
        $this->actingAs($this->both)->getJson("/people/{$p->id}/timeline")->assertOk();
    }

    public function test_creer_une_fiche_en_famille_reste_dans_le_repertoire(): void
    {
        $this->actingAs($this->family)->postJson('/people', ['name' => 'Cousin Germain'])->assertCreated();

        $p = Person::where('name', 'Cousin Germain')->firstOrFail();
        $this->assertTrue($p->in_family_directory);
        $this->assertSame(0, $p->circles()->count());
    }

    public function test_recherche_n_expose_pas_les_autres_cercles(): void
    {
        $names = fn (User $u, string $q) => collect($this->actingAs($u)->getJson('/search?q='.urlencode($q))->assertOk()->json('people'))->pluck('id');

        $this->assertFalse($names($this->family, 'Copine')->contains($this->friendP->id));
        $this->assertTrue($names($this->friend, 'Copine')->contains($this->friendP->id));
        $this->assertFalse($names($this->friend, 'Famille')->contains($this->famP->id));
    }

    public function test_reseau_limite_au_cercle_courant(): void
    {
        $media = Media::factory()->create(['user_id' => $this->both->id, 'type' => 'photo']);
        $media->people()->attach([$this->famP->id, $this->friendP->id]);
        $media2 = Media::factory()->create(['user_id' => $this->both->id, 'type' => 'photo']);
        $other = $this->circlePerson($this->A, 'Autre Pote');
        $media2->people()->attach([$this->friendP->id, $other->id]);

        $this->both->forceFill(['current_household_id' => $this->A->id])->save();
        $graph = $this->actingAs($this->both->fresh())->getJson('/network/data')->assertOk()->json();

        $nodeIds = collect($graph['nodes'])->pluck('id');
        $this->assertTrue($nodeIds->contains($this->friendP->id));
        $this->assertFalse($nodeIds->contains($this->famP->id));
        $this->assertCount(1, $graph['edges']);
    }
}
