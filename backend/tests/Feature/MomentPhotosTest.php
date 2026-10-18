<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Photos ↔ moments : sélecteur /media/pick (onglets, accès, recherche) et
 * moments d'une photo sur sa page.
 */
class MomentPhotosTest extends TestCase
{
    use RefreshDatabase;

    private function ids($response): array
    {
        return array_column($response->assertOk()->json('data'), 'id');
    }

    public function test_recentes_mes_envois_les_plus_recents_d_abord(): void
    {
        $me = User::factory()->create();
        $old = Media::factory()->photo()->create(['user_id' => $me->id, 'uploaded_at' => now()->subDays(3)]);
        $new = Media::factory()->photo()->create(['user_id' => $me->id, 'uploaded_at' => now()]);
        Media::factory()->photo()->create(); // un autre compte, inaccessible

        $this->assertSame([$new->id, $old->id], $this->ids($this->actingAs($me)->getJson('/media/pick?tab=recent')));
    }

    public function test_autour_d_une_date_les_plus_proches_d_abord(): void
    {
        $me = User::factory()->create();
        $near = Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => '2020-06-21 12:00:00']);
        $far = Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => '2020-12-01 12:00:00']);
        Media::factory()->photo()->create(['user_id' => $me->id, 'taken_at' => '2015-06-20 12:00:00']); // hors fenêtre

        $this->assertSame([$near->id, $far->id], $this->ids($this->actingAs($me)->getJson('/media/pick?tab=date&around=2020-06-20')));
    }

    public function test_avec_une_personne_et_un_album_accessible(): void
    {
        $me = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $me->id]);
        $tagged = Media::factory()->photo()->create(['user_id' => $me->id]);
        $tagged->people()->attach($person->id);
        Media::factory()->photo()->create(['user_id' => $me->id]);

        $this->assertSame([$tagged->id], $this->ids($this->actingAs($me)->getJson("/media/pick?tab=person&person_id={$person->id}")));

        $album = Album::factory()->create(['user_id' => $me->id]);
        $inAlbum = Media::factory()->photo()->create(['user_id' => $me->id]);
        $album->media()->attach($inAlbum->id);
        $this->assertSame([$inAlbum->id], $this->ids($this->actingAs($me)->getJson("/media/pick?tab=album&album_id={$album->id}")));

        // Album d'un autre (non partagé) : rien
        $foreign = Album::factory()->create();
        $foreignMedia = Media::factory()->photo()->create(['user_id' => $foreign->user_id]);
        $foreign->media()->attach($foreignMedia->id);
        $this->assertSame([], $this->ids($this->actingAs($me)->getJson("/media/pick?tab=album&album_id={$foreign->id}")));
    }

    public function test_recherche_par_titre(): void
    {
        $me = User::factory()->create();
        $hit = Media::factory()->photo()->create(['user_id' => $me->id, 'title' => 'Parc Astérix']);
        Media::factory()->photo()->create(['user_id' => $me->id, 'title' => 'Plage']);

        // Sans accent ni majuscule
        $this->assertSame([$hit->id], $this->ids($this->actingAs($me)->getJson('/media/pick?tab=recent&q=asterix')));
        $this->assertSame([$hit->id], $this->ids($this->actingAs($me)->getJson('/media/pick?tab=recent&q=ASTÉRIX')));
    }

    public function test_la_page_photo_liste_ses_moments(): void
    {
        $me = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $me->id, 'name' => 'Marion Test']);
        $photo = Media::factory()->photo()->create(['user_id' => $me->id]);

        $asIllustration = LifeEvent::create(['person_id' => $person->id, 'user_id' => $me->id, 'type' => 'mariage', 'title' => 'Mariage', 'event_date' => '2020-06-20', 'media_id' => $photo->id]);
        $inGallery = LifeEvent::create(['person_id' => $person->id, 'user_id' => $me->id, 'type' => 'voyage', 'title' => 'Voyage', 'event_date' => '2021-05-01']);
        $inGallery->photos()->attach($photo->id, ['order' => 0]);
        LifeEvent::create(['person_id' => $person->id, 'user_id' => $me->id, 'type' => 'moment', 'title' => 'Autre', 'event_date' => '2022-01-01']);

        $this->actingAs($me)->get("/media/{$photo->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Media/Show', false)
                ->where('moments.0.id', $asIllustration->id)
                ->where('moments.1.id', $inGallery->id)
                ->where('moments.0.person.name', 'Marion Test')
                ->count('moments', 2));
    }

    public function test_creer_un_moment_avec_des_photos_choisies(): void
    {
        $me = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $me->id]);
        $a = Media::factory()->photo()->create(['user_id' => $me->id]);
        $b = Media::factory()->photo()->create(['user_id' => $me->id]);

        $this->actingAs($me)->postJson("/people/{$person->id}/events", [
            'type' => 'voyage', 'title' => 'Astérix', 'event_date' => '2024-06-05',
            'media_id' => $b->id, 'photos' => [$a->id, $b->id],
        ])->assertCreated();

        $event = LifeEvent::where('title', 'Astérix')->firstOrFail();
        $this->assertSame($b->id, $event->media_id);
        $this->assertSame([$a->id, $b->id], $event->photos()->pluck('media.id')->all());
    }
}
