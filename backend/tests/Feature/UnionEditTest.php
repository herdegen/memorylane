<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Person;
use App\Models\PersonChange;
use App\Models\PersonRelationship;
use App\Models\User;
use App\Services\PersonChangeReverter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Carte « Mariage » de la frise : la date, le lieu et la photo de l'union se
 * modifient depuis la frise, sont journalisés sur les deux fiches et
 * s'annulent depuis l'historique de l'arbre.
 */
class UnionEditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Person $a;

    private Person $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->a = Person::factory()->create(['user_id' => $this->admin->id]);
        $this->b = Person::factory()->create(['user_id' => $this->admin->id]);
        $ids = [$this->a->id, $this->b->id];
        sort($ids);
        PersonRelationship::create(['person1_id' => $ids[0], 'person2_id' => $ids[1], 'type' => 'spouse', 'start_date' => '1984-05-05', 'start_place' => 'Montigny-lès-Metz']);
    }

    private function union(): PersonRelationship
    {
        return PersonRelationship::where(fn ($q) => $q->where('person1_id', $this->a->id)->orWhere('person2_id', $this->a->id))->sole();
    }

    public function test_modifier_date_lieu_et_photo_de_l_union(): void
    {
        $photo = Media::factory()->create(['user_id' => $this->admin->id]);

        $this->actingAs($this->admin)->putJson("/people/{$this->a->id}/spouse", [
            'spouse_id' => $this->b->id,
            'start_date' => '1984-05-12',
            'start_place' => 'Metz, Moselle, France',
            'media_id' => $photo->id,
        ])->assertOk();

        $union = $this->union();
        $this->assertSame('1984-05-12', $union->start_date->toDateString());
        $this->assertSame('Metz, Moselle, France', $union->start_place);
        $this->assertSame($photo->id, $union->media_id);
        // Journalisé sur les deux fiches.
        $this->assertSame(2, PersonChange::where('action', 'union_updated')->count());

        $marriage = collect($this->actingAs($this->admin)->getJson("/people/{$this->a->id}/timeline")->json())->firstWhere('kind', 'marriage');
        $this->assertSame($photo->id, $marriage['union']['media_id']);
        $this->assertSame($photo->id, $marriage['media']['id']);
        $this->assertSame('1984-05-12', $marriage['date']);
    }

    public function test_une_photo_inaccessible_est_refusee(): void
    {
        $hidden = Media::factory()->create(['user_id' => User::factory()->create()->id]);
        $member = User::factory()->create();
        $this->a->update(['user_id' => $member->id]);
        $this->b->update(['user_id' => $member->id]);

        $this->actingAs($member)->putJson("/people/{$this->a->id}/spouse", [
            'spouse_id' => $this->b->id, 'start_date' => '1984-05-05', 'media_id' => $hidden->id,
        ])->assertForbidden();
        $this->assertNull($this->union()->media_id);
    }

    public function test_l_historique_de_l_arbre_annule_la_modification(): void
    {
        $this->actingAs($this->admin)->putJson("/people/{$this->a->id}/spouse", [
            'spouse_id' => $this->b->id, 'start_date' => '1990-01-01', 'start_place' => 'Paris',
        ])->assertOk();

        $change = PersonChange::where('person_id', $this->a->id)->where('action', 'union_updated')->sole();
        $this->assertTrue($change->isRevertible());
        app(PersonChangeReverter::class)->revert($change, $this->admin);

        $union = $this->union();
        $this->assertSame('1984-05-05', $union->start_date->toDateString());
        $this->assertSame('Montigny-lès-Metz', $union->start_place);
        // La ligne jumelle (fiche du conjoint) est close aussi.
        $this->assertNotNull(PersonChange::where('person_id', $this->b->id)->where('action', 'union_updated')->first()->reverted_at);
    }
}
