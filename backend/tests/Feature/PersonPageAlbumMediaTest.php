<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une photo d'un album partagé avec le groupe, où une personne est
 * identifiée, apparaît sur sa fiche pour tous les membres du groupe — sans
 * « Mettre sur sa fiche » (qui sert à la montrer AU-DELÀ des accès à l'album).
 */
class PersonPageAlbumMediaTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_d_un_album_du_groupe_visible_sur_la_fiche(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $friends = Household::factory()->friends()->create();
        $friends->members()->attach([$owner->id, $member->id]);

        $lea = Person::factory()->create(['name' => 'Léa', 'in_family_directory' => false, 'user_id' => $owner->id]);
        $lea->circles()->attach($friends->id);

        $album = Album::factory()->create(['user_id' => $owner->id]);
        $album->households()->attach($friends->id);
        $photo = Media::factory()->photo()->create(['user_id' => $owner->id]);
        $album->media()->attach($photo->id);
        $photo->people()->attach($lea->id);

        $member->forceFill(['current_household_id' => $friends->id])->save();
        $this->actingAs($member)->get("/people/{$lea->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('People/Show', false)
                ->where('media', fn ($media) => collect($media)->pluck('id')->contains($photo->id)));

        // Hors du groupe : ni la fiche, ni la photo
        $this->actingAs($outsider)->get("/people/{$lea->id}")->assertNotFound();
    }

    public function test_page_photo_liste_les_presents_sur_l_album(): void
    {
        $me = User::factory()->create();
        $paul = Person::factory()->create(['name' => 'Paul Cousin']);
        $album = Album::factory()->create(['user_id' => $me->id]);
        $photo = Media::factory()->photo()->create(['user_id' => $me->id]);
        $other = Media::factory()->photo()->create(['user_id' => $me->id]);
        $album->media()->attach([$photo->id, $other->id]);
        $other->people()->attach($paul->id);

        $this->actingAs($me)->get("/media/{$photo->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Media/Show', false)
                ->where('albumPeople', [['id' => $paul->id, 'name' => 'Paul Cousin']]));
    }
}
