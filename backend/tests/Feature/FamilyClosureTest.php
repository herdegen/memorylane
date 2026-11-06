<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Notifications\FamilyClosureRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Fermer une famille : demande d'un responsable, 30 jours pour la reprendre,
 * puis fermeture — comptes et fiches communes gardés, fiches orphelines
 * archivées sans photos, fiche d'un compte laissée à la personne.
 */
class FamilyClosureTest extends TestCase
{
    use RefreshDatabase;

    private User $chief;

    private User $cousin;

    private Household $family;

    private Household $other;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->chief = User::factory()->create();
        $this->cousin = User::factory()->create();
        $this->family = Household::factory()->create(['name' => 'Famille P', 'created_by' => $this->chief->id]);
        $this->family->addMember($this->chief, $this->chief, Household::ROLE_MANAGER);
        $this->family->addMember($this->cousin, $this->chief);
        $this->other = Household::factory()->create(['name' => 'Famille K', 'created_by' => $this->chief->id]);
        $this->other->addMember($this->chief, $this->chief, Household::ROLE_MANAGER);
    }

    public function test_demande_prevenue_puis_reprise_par_un_membre(): void
    {
        $this->actingAs($this->cousin)->postJson("/households/{$this->family->id}/closure")->assertForbidden();
        $this->actingAs($this->chief)->postJson("/households/{$this->family->id}/closure")->assertOk();
        Notification::assertSentTo($this->cousin, FamilyClosureRequested::class);
        Notification::assertNotSentTo($this->chief, FamilyClosureRequested::class);

        // Supprimer d'un clic : refusé (sauf l'admin).
        $this->actingAs($this->chief)->deleteJson("/households/{$this->family->id}")->assertUnprocessable();

        $this->actingAs($this->cousin)->postJson("/households/{$this->family->id}/takeover")->assertOk();
        $this->family->refresh();
        $this->assertNull($this->family->closure_requested_at);
        $this->assertTrue($this->family->isManager($this->cousin));
    }

    public function test_a_echeance_la_famille_ferme_et_ses_fiches_sont_traitees(): void
    {
        $this->actingAs($this->chief);
        $shared = Person::factory()->create(['user_id' => $this->chief->id, 'name' => 'Ancêtre commun']);
        $this->other->people()->syncWithoutDetaching([$shared->id]);
        $this->family->people()->syncWithoutDetaching([$shared->id]);
        $this->chief->forceFill(['current_household_id' => $this->family->id])->save();
        $orphan = Person::factory()->create(['user_id' => $this->chief->id, 'name' => 'Seulement chez P']);
        $own = Person::factory()->create(['user_id' => $this->cousin->id, 'name' => 'La cousine']);
        $this->family->people()->syncWithoutDetaching([$orphan->id, $own->id]);
        $this->other->people()->detach([$orphan->id, $own->id]);
        $this->cousin->forceFill(['person_id' => $own->id])->save();
        $photo = Media::factory()->create(['user_id' => $this->chief->id, 'type' => 'photo']);
        $photo->people()->attach($orphan->id);

        $this->postJson("/households/{$this->family->id}/closure")->assertOk();

        // Avant l'échéance : rien ne bouge.
        Carbon::setTestNow(now()->addDays(29));
        $this->artisan('households:process-closures')->assertSuccessful();
        $this->assertNotNull(Household::find($this->family->id));

        Carbon::setTestNow(now()->addDays(2));
        $this->artisan('households:process-closures')->assertSuccessful();
        Carbon::setTestNow();

        $this->assertNull(Household::find($this->family->id));
        $this->assertNotNull(User::find($this->cousin->id), 'les comptes restent');
        $this->assertNull($shared->fresh()->archived_at, 'la fiche commune reste dans l’autre famille');
        $this->assertNotNull($orphan->fresh()->archived_at, 'la fiche orpheline est archivée');
        $this->assertSame(0, $orphan->fresh()->media()->count(), 'sans ses photos');
        $this->assertFalse(Person::visibleTo($this->chief->fresh())->whereKey($orphan->id)->exists(), 'invisible, même de son auteur');
        $this->assertNull($own->fresh()->archived_at, 'la fiche d’un compte reste à la personne');
        $this->assertTrue(Person::visibleTo($this->cousin->fresh())->whereKey($own->id)->exists());
    }

    public function test_annulation_par_un_responsable(): void
    {
        $this->actingAs($this->chief)->postJson("/households/{$this->family->id}/closure")->assertOk();
        $this->actingAs($this->chief)->deleteJson("/households/{$this->family->id}/closure")->assertOk();
        $this->assertNull($this->family->fresh()->closure_requested_at);
    }
}
