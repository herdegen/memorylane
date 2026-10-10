<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Partage d'un album avec un cercle entier (famille et/ou amis) : un album de
 * mariage partagé aux deux cercles est visible de tous leurs membres.
 */
class AlbumCircleShareTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cousin;
    private User $friend;
    private User $stranger;
    private Household $family;
    private Household $friends;
    private Album $album;
    private Media $photo;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $this->cousin, $this->friend, $this->stranger] = User::factory()->count(4)->create()->all();

        $this->family = Household::factory()->create();
        $this->friends = Household::factory()->friends()->create();
        $this->family->members()->attach([$this->owner->id, $this->cousin->id]);
        $this->friends->members()->attach([$this->owner->id, $this->friend->id]);

        $this->album = Album::factory()->create(['user_id' => $this->owner->id, 'name' => 'Mariage']);
        $this->photo = Media::factory()->create(['user_id' => $this->owner->id, 'type' => 'photo']);
        $this->album->media()->attach($this->photo->id);
    }

    public function test_mariage_partage_avec_la_famille_et_les_amis(): void
    {
        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/circles", ['household_id' => $this->family->id])->assertOk();
        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/circles", ['household_id' => $this->friends->id])
            ->assertOk()->assertJsonCount(2, 'shared')->assertJsonCount(0, 'available');

        foreach ([$this->cousin, $this->friend] as $viewer) {
            $this->actingAs($viewer)->get("/albums/{$this->album->id}")->assertOk();
            $this->actingAs($viewer)->get("/media/{$this->photo->id}")->assertOk();
        }
        $this->actingAs($this->stranger)->get("/albums/{$this->album->id}")->assertForbidden();
    }

    public function test_un_nouveau_membre_du_cercle_y_a_acces(): void
    {
        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/circles", ['household_id' => $this->friends->id])->assertOk();

        $this->friends->members()->attach($this->stranger->id);

        $this->actingAs($this->stranger->fresh())->get("/albums/{$this->album->id}")->assertOk();
    }

    public function test_on_ne_partage_qu_avec_ses_propres_cercles(): void
    {
        $other = Household::factory()->create();

        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/circles", ['household_id' => $other->id])->assertForbidden();
        $this->actingAs($this->stranger)->postJson("/albums/{$this->album->id}/circles", ['household_id' => $this->family->id])->assertForbidden();
    }

    public function test_retrait_par_le_proprietaire_ou_l_auteur_du_partage(): void
    {
        // Le cousin (accès via la famille) partage à son tour avec un cercle à lui.
        $club = Household::factory()->friends()->create();
        $club->members()->attach($this->cousin->id);
        $this->actingAs($this->owner)->postJson("/albums/{$this->album->id}/circles", ['household_id' => $this->family->id])->assertOk();
        $this->actingAs($this->cousin)->postJson("/albums/{$this->album->id}/circles", ['household_id' => $club->id])->assertOk();

        // Un membre de la famille ne retire pas le partage du propriétaire…
        $this->actingAs($this->cousin)->deleteJson("/albums/{$this->album->id}/circles/{$this->family->id}")->assertForbidden();
        // … mais retire le sien ; le propriétaire peut tout retirer.
        $this->actingAs($this->cousin)->deleteJson("/albums/{$this->album->id}/circles/{$club->id}")->assertOk();
        $this->actingAs($this->owner)->deleteJson("/albums/{$this->album->id}/circles/{$this->family->id}")->assertOk();

        $this->actingAs($this->cousin)->get("/albums/{$this->album->id}")->assertForbidden();
    }
}
