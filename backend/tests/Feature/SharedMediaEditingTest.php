<?php

namespace Tests\Feature;

use App\Models\DetectedFace;
use App\Models\Household;
use App\Models\Media;
use App\Models\MediaChange;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Édition partagée d'une photo : tout membre qui la voit corrige sa date, son
 * lieu et ses visages ; chaque correction est journalisée et annulable par
 * l'auteur de la photo, l'admin ou la personne qui l'a faite.
 */
class SharedMediaEditingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $member;

    private User $otherMember;

    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->otherMember = User::factory()->create();
        $this->media = Media::factory()->create(['user_id' => $this->owner->id, 'taken_at' => '1975-03-12 12:00:00']);
        $household = Household::factory()->create(['created_by' => $this->owner->id]);
        $household->members()->attach([$this->owner->id, $this->member->id, $this->otherMember->id]);
        $household->media()->attach($this->media->id, ['added_by' => $this->owner->id]);
    }

    private function face(): DetectedFace
    {
        return DetectedFace::create([
            'media_id' => $this->media->id,
            'bounding_box' => ['x' => 10, 'y' => 15, 'width' => 20, 'height' => 25],
            'confidence' => 0.95,
            'provider' => 'manual',
            'status' => 'unmatched',
        ]);
    }

    public function test_un_membre_corrige_la_date_et_c_est_journalise(): void
    {
        $this->actingAs($this->member)
            ->putJson("/media/{$this->media->id}", ['title' => null, 'description' => null, 'taken_at' => '1972-06-01 12:00:00'])
            ->assertOk();

        $this->assertSame('1972-06-01', $this->media->fresh()->taken_at->toDateString());
        $change = MediaChange::where('media_id', $this->media->id)->sole();
        $this->assertSame('updated', $change->action);
        $this->assertSame($this->member->id, $change->user_id);
        $this->assertSame(['1975-03-12 12:00:00', '1972-06-01 12:00:00'], $change->diff['taken_at']);
    }

    public function test_un_compte_sans_acces_ou_un_invite_ne_corrige_pas(): void
    {
        $stranger = User::factory()->create();
        $this->actingAs($stranger)->putJson("/media/{$this->media->id}", ['taken_at' => '1972-06-01'])->assertForbidden();
        $this->actingAs($stranger)->postJson("/map/media/{$this->media->id}/geolocation", ['latitude' => 49, 'longitude' => 6])->assertForbidden();

        $guest = User::factory()->create(['guest_expires_at' => now()->addDay()]);
        $this->assertFalse($guest->can('editDetails', $this->media));
        $this->assertSame('1975-03-12', $this->media->fresh()->taken_at->toDateString());
    }

    public function test_un_membre_place_la_photo_et_l_auteur_annule(): void
    {
        $this->actingAs($this->member)
            ->postJson("/map/media/{$this->media->id}/geolocation", ['latitude' => 49.19, 'longitude' => 6.9])
            ->assertOk();
        $change = MediaChange::where('media_id', $this->media->id)->sole();
        $this->assertSame('geolocated', $change->action);

        $this->actingAs($this->owner)->postJson("/media/changes/{$change->id}/revert")->assertOk();
        $this->assertNull($this->media->fresh()->metadata->latitude);
        $this->assertNotNull($change->fresh()->reverted_at);
    }

    public function test_un_membre_identifie_un_visage_et_peut_annuler_sa_correction(): void
    {
        $face = $this->face();
        // Naissance fixée : on ne nomme pas quelqu'un sur une photo antérieure à sa naissance.
        $person = Person::factory()->create(['user_id' => $this->owner->id, 'birth_date' => '1940-01-01']);

        $this->actingAs($this->member)
            ->postJson("/vision/faces/{$face->id}/match", ['person_id' => $person->id])
            ->assertOk();
        $this->assertSame($person->id, $face->fresh()->person_id);
        $change = MediaChange::where('media_id', $this->media->id)->where('action', 'face_identified')->sole();

        // Un autre membre (ni auteur de la photo, ni auteur de la correction) n'annule pas.
        $this->actingAs($this->otherMember)->postJson("/media/changes/{$change->id}/revert")->assertForbidden();

        $this->actingAs($this->member)->postJson("/media/changes/{$change->id}/revert")->assertOk();
        $this->assertNull($face->fresh()->person_id);
        $this->assertSame('unmatched', $face->fresh()->status);
    }

    public function test_l_analyse_serveur_reste_reservee_a_l_auteur_et_a_l_admin(): void
    {
        $this->actingAs($this->member)->postJson("/vision/media/{$this->media->id}/analyze")->assertForbidden();
    }

    public function test_la_page_de_la_photo_expose_les_droits_et_l_historique(): void
    {
        $this->actingAs($this->member)->putJson("/media/{$this->media->id}", ['taken_at' => '1972-06-01 12:00:00'])->assertOk();

        $this->actingAs($this->otherMember)->get("/media/{$this->media->id}")
            ->assertInertia(fn ($page) => $page
                ->where('can.edit_details', true)
                ->has('history', 1)
                ->where('history.0.action', 'updated')
                ->where('history.0.can_revert', false));

        $this->actingAs($this->owner)->get("/media/{$this->media->id}")
            ->assertInertia(fn ($page) => $page->where('history.0.can_revert', true));
    }

    public function test_dater_a_la_decennie_et_rester_hors_des_souvenirs_du_jour(): void
    {
        $this->actingAs($this->member)
            ->putJson("/media/{$this->media->id}", ['taken_at' => '1974-06-15', 'taken_at_precision' => 'decade'])
            ->assertOk();

        $media = $this->media->fresh();
        $this->assertSame('1970-01-01 12:00:00', $media->taken_at->format('Y-m-d H:i:s'));
        $this->assertSame('decade', $media->taken_at_precision);
        $this->assertFalse(Media::whereKey($media->id)->exactDate()->exists());

        $this->actingAs($this->owner)->get("/media/{$this->media->id}")
            ->assertInertia(fn ($page) => $page->where('history.0.summary', fn ($text) => str_contains($text, 'années 1970')));

        // Revenir à une date exacte efface la précision.
        $this->actingAs($this->member)->putJson("/media/{$this->media->id}", ['taken_at' => '1972-06-01 10:30:00'])->assertOk();
        $this->assertNull($this->media->fresh()->taken_at_precision);
        $this->assertTrue(Media::whereKey($media->id)->exactDate()->exists());
    }

    public function test_dater_en_masse_a_l_annee(): void
    {
        $this->actingAs($this->owner)->postJson('/media/bulk/taken-at', [
            'media_ids' => [$this->media->id], 'taken_at' => '1965-05-20', 'taken_at_precision' => 'year',
        ])->assertOk();

        $media = $this->media->fresh();
        $this->assertSame('1965-01-01', $media->taken_at->toDateString());
        $this->assertSame('year', $media->taken_at_precision);
    }

    public function test_la_question_de_l_accueil_accepte_une_decennie(): void
    {
        $this->media->update(['taken_at' => null]);

        $this->actingAs($this->member)->postJson('/quests/answer', [
            'question_type' => 'media_date',
            'subject_id' => $this->media->id,
            'answer_kind' => 'answered',
            'payload' => ['value' => '1958-01-01', 'precision' => 'decade'],
        ])->assertSuccessful();

        $media = $this->media->fresh();
        $this->assertSame('1950-01-01', $media->taken_at->toDateString());
        $this->assertSame('decade', $media->taken_at_precision);
        $this->assertSame(1, MediaChange::where('media_id', $media->id)->count());
    }
}
