<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Push\FicheMediaPending;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * « Ajouter à la fiche » : visible de tous ceux qui voient la fiche ; si la
 * personne a un compte, elle valide d'abord.
 */
class FichePublicationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cousin;

    private Media $video;

    protected function setUp(): void
    {
        parent::setUp();
        $family = Household::factory()->create(['kind' => 'family']);
        $this->owner = User::factory()->create();
        $this->cousin = User::factory()->create();
        $family->members()->attach([$this->owner->id, $this->cousin->id]);
        $this->video = Media::factory()->video()->create(['user_id' => $this->owner->id, 'taken_at' => '1957-08-01']);
    }

    private function person(array $attrs = []): Person
    {
        return Person::factory()->create(['user_id' => $this->owner->id, 'in_family_directory' => true, 'birth_date' => '1930-01-01', ...$attrs]);
    }

    public function test_on_the_fiche_of_someone_without_an_account_it_is_visible_to_all_at_once(): void
    {
        $grandpa = $this->person(['name' => 'Papy Jean']);
        $this->assertFalse($this->cousin->can('view', $this->video), 'privé au départ');

        $this->actingAs($this->owner)->postJson("/media/{$this->video->id}/fiches", ['person_id' => $grandpa->id])
            ->assertOk()->assertJson(['status' => 'published']);

        $this->assertTrue($this->cousin->fresh()->can('view', $this->video));
        $this->actingAs($this->cousin)->postJson("/media/{$this->video->id}/fiches", ['person_id' => $grandpa->id])->assertForbidden();

        // Retiré : de nouveau privé, mais toujours identifié.
        $this->actingAs($this->owner)->deleteJson("/media/{$this->video->id}/fiches/{$grandpa->id}")->assertOk();
        $this->assertFalse($this->cousin->fresh()->can('view', $this->video));
        $this->assertTrue($this->video->people()->whereKey($grandpa->id)->exists());
    }

    public function test_someone_with_an_account_validates_first(): void
    {
        Notification::fake();
        $aunt = $this->person(['name' => 'Tante Lucie']);
        $auntAccount = User::factory()->create(['person_id' => $aunt->id]);
        $auntAccount->updatePushSubscription('https://push.example.org/lucie', 'k', 't');
        $auntAccount->setPreference('push', ['social' => true, 'birthdays' => true, 'digest' => true]);

        $this->actingAs($this->owner)->postJson("/media/{$this->video->id}/fiches", ['person_id' => $aunt->id])
            ->assertOk()->assertJson(['status' => 'pending']);

        Notification::assertSentTo($auntAccount, FicheMediaPending::class, fn ($n) => $n->mediaId === $this->video->id);
        $this->assertFalse($this->cousin->fresh()->can('view', $this->video), 'pas avant sa validation');
        $this->assertTrue($auntAccount->fresh()->can('view', $this->video), 'elle le voit pour décider');
        $this->actingAs($auntAccount)->get('/dashboard')->assertInertia(fn ($page) => $page->where('fichePending.0.id', $this->video->id));
        $this->actingAs($auntAccount)->get("/media/{$this->video->id}")->assertInertia(fn ($page) => $page->where('ficheRequest.by', $this->owner->name));

        $this->actingAs($this->cousin)->postJson("/media/{$this->video->id}/fiche-decision", ['publish' => true])->assertNotFound();
        $this->actingAs($auntAccount)->postJson("/media/{$this->video->id}/fiche-decision", ['publish' => true])->assertOk();
        $this->assertTrue($this->cousin->fresh()->can('view', $this->video));
        $this->actingAs($auntAccount)->get('/dashboard')->assertInertia(fn ($page) => $page->where('fichePending', []));

        // Elle peut la retirer de sa fiche ensuite.
        $this->actingAs($auntAccount)->deleteJson("/media/{$this->video->id}/fiches/{$aunt->id}")->assertOk();
        $this->assertFalse($this->cousin->fresh()->can('view', $this->video));
    }

    public function test_kept_private_stays_between_the_two(): void
    {
        $aunt = $this->person(['name' => 'Tante Lucie']);
        $auntAccount = User::factory()->create(['person_id' => $aunt->id]);
        $this->actingAs($this->owner)->postJson("/media/{$this->video->id}/fiches", ['person_id' => $aunt->id])->assertOk();

        $this->actingAs($auntAccount)->postJson("/media/{$this->video->id}/fiche-decision", ['publish' => false])->assertOk();

        $this->assertFalse($this->cousin->fresh()->can('view', $this->video));
        $this->assertTrue($auntAccount->fresh()->can('view', $this->video));
    }

    public function test_not_on_the_fiche_of_someone_born_after_the_video(): void
    {
        $child = $this->person(['name' => 'Bébé', 'birth_date' => '1990-01-01']);
        $this->actingAs($this->owner)->postJson("/media/{$this->video->id}/fiches", ['person_id' => $child->id])->assertStatus(422);
    }
}
