<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\QuestService;
use App\Services\Vision\FaceMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Visages d'inconnus sur les photos de voyage : « Qui était là ? » (participants
 * d'un album), « Personne de connu ici », arrière-plan hors quêtes.
 */
class AlbumParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Album $trip;
    private Person $me;
    private Person $marion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->me = $this->person('Matthieu');
        $this->marion = $this->person('Marion');
        // Références : un visage identifié de chacun, ailleurs.
        $this->face($this->photo(), 0.10, $this->me);
        $this->face($this->photo(), 0.90, $this->marion);

        $this->trip = Album::factory()->create(['user_id' => $this->user->id, 'name' => 'Rome 2023']);
    }

    private function photo(): Media
    {
        return Media::factory()->create(['user_id' => $this->user->id, 'type' => 'photo', 'taken_at' => '2023-05-01']);
    }

    private function face(Media $media, float $seed, ?Person $person = null, float $width = 10): DetectedFace
    {
        $vec = array_fill(0, 128, 0.0);
        $vec[0] = $seed;

        return DetectedFace::create([
            'media_id' => $media->id,
            'bounding_box' => ['x' => 1, 'y' => 1, 'width' => $width, 'height' => $width],
            'provider' => 'faceapi',
            'status' => $person ? 'matched' : 'unmatched',
            'person_id' => $person?->id,
            'embedding' => $vec,
        ]);
    }

    private function person(string $name): Person
    {
        return Person::create(['user_id' => $this->user->id, 'name' => $name]);
    }

    private function tripPhotoWithFaces(float ...$seeds): array
    {
        $photo = $this->photo();
        $this->trip->media()->attach($photo->id);

        return array_map(fn ($s) => $this->face($photo, $s), $seeds);
    }

    public function test_les_inconnus_du_voyage_sont_ranges_et_les_participants_restent_a_identifier(): void
    {
        // 0.12 ressemble à Matthieu ; 5.0 et 7.0 ne ressemblent à personne (passants).
        [$likeMe, $passerby1, $passerby2] = $this->tripPhotoWithFaces(0.12, 5.0, 7.0);

        $this->actingAs($this->user)
            ->putJson("/albums/{$this->trip->id}/participants", ['person_ids' => [$this->me->id, $this->marion->id]])
            ->assertOk()
            ->assertJsonPath('auto_rejected', 2)
            ->assertJsonPath('to_identify', 1)
            ->assertJsonPath('missing_refs', []);

        $this->assertSame('unmatched', $likeMe->fresh()->status);
        foreach ([$passerby1, $passerby2] as $f) {
            $this->assertSame(FaceMatcher::REJECTED_STATUS, $f->fresh()->status);
            $this->assertSame($this->trip->id, $f->fresh()->auto_rejected_album_id);
        }

        // Réversible : plus de participants → les visages reviennent « à identifier ».
        $this->actingAs($this->user)->putJson("/albums/{$this->trip->id}/participants", ['person_ids' => []])->assertOk();
        $this->assertSame('unmatched', $passerby1->fresh()->status);
        $this->assertNull($passerby1->fresh()->auto_rejected_album_id);
    }

    public function test_un_participant_sans_visage_connu_bloque_le_tri(): void
    {
        $eliott = $this->person('Eliott'); // jamais identifié
        [$passerby] = $this->tripPhotoWithFaces(5.0);

        $this->actingAs($this->user)
            ->putJson("/albums/{$this->trip->id}/participants", ['person_ids' => [$this->me->id, $eliott->id]])
            ->assertOk()
            ->assertJsonPath('auto_rejected', 0)
            ->assertJsonPath('missing_refs', ['Eliott']);

        $this->assertSame('unmatched', $passerby->fresh()->status);
    }

    public function test_les_suggestions_se_limitent_aux_participants(): void
    {
        $stranger = $this->person('Cousin Lointain');
        $this->face($this->photo(), 0.13, $stranger); // ressemble aussi au visage cible
        [$target] = $this->tripPhotoWithFaces(0.12);
        $this->trip->participants()->attach($this->me->id);

        $names = collect(app(FaceMatcher::class)->rankedCandidates($target, app(FaceMatcher::class)->matchThreshold(), $this->user->id))
            ->pluck('person.name');

        $this->assertContains('Matthieu', $names);
        $this->assertNotContains('Cousin Lointain', $names);
    }

    public function test_personne_de_connu_ici_range_les_visages_non_nommes_seulement(): void
    {
        $photo = $this->photo();
        $named = $this->face($photo, 0.10, $this->me);
        $unknown = $this->face($photo, 5.0);
        $otherUsersPhoto = Media::factory()->create(['type' => 'photo']);
        $notMine = $this->face($otherUsersPhoto, 6.0);

        $this->actingAs($this->user)
            ->postJson('/vision/no-known-faces', ['media_ids' => [$photo->id, $otherUsersPhoto->id]])
            ->assertOk()
            ->assertJsonPath('marked', 1);

        $this->assertSame(FaceMatcher::REJECTED_STATUS, $unknown->fresh()->status);
        $this->assertSame('matched', $named->fresh()->status);
        $this->assertSame('unmatched', $notMine->fresh()->status); // photo qu'il ne voit pas
    }

    public function test_les_visages_d_arriere_plan_ne_sont_pas_proposes_en_quete(): void
    {
        $this->user->forceFill(['person_id' => $this->me->id])->save();
        $photo = $this->photo();
        $main = $this->face($photo, 3.0, null, 12);      // visage au premier plan
        $tiny = $this->face($photo, 4.0, null, 1.0);     // minuscule
        $far = $this->face($photo, 6.0, null, 3.0);      // 4× plus petit que le principal

        $service = app(QuestService::class);
        $service->forget($this->user);
        $keys = array_map(fn ($c) => $c['type'].':'.$c['subject_id'], $service->candidates($this->user->fresh()));

        $this->assertContains("face_identify:{$main->id}", $keys);
        $this->assertNotContains("face_identify:{$tiny->id}", $keys);
        $this->assertNotContains("face_identify:{$far->id}", $keys);
    }
}
