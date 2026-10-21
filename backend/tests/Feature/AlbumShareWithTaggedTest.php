<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Push\AlbumAccessGranted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * « Partager tout l'album avec les personnes identifiées dedans » : réglage
 * permanent (personnes futures comprises), identifications humaines seulement.
 */
class AlbumShareWithTaggedTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Album $album;
    private Media $withPaul;
    private Media $landscape;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->album = Album::factory()->create(['user_id' => $this->owner->id]);
        $this->withPaul = Media::factory()->photo()->create(['user_id' => $this->owner->id]);
        $this->landscape = Media::factory()->photo()->create(['user_id' => $this->owner->id]);
        $this->album->media()->attach([$this->withPaul->id => ['order' => 0], $this->landscape->id => ['order' => 1]]);
    }

    /** Compte relié à une fiche identifiée à la main sur une photo. */
    private function taggedAccount(Media $media, string $name): User
    {
        $person = Person::factory()->create(['user_id' => $this->owner->id, 'first_name' => $name]);
        $user = User::factory()->create(['name' => $name, 'person_id' => $person->id]);
        $media->people()->attach($person->id);

        return $user;
    }

    private function seesLandscape(User $user): bool
    {
        return Media::accessibleBy($user)->whereKey($this->landscape->id)->exists();
    }

    public function test_l_album_entier_s_ouvre_aux_identifies_y_compris_plus_tard(): void
    {
        Notification::fake();
        $paul = $this->taggedAccount($this->withPaul, 'Paul');
        $paul->updatePushSubscription('https://push.example.org/paul', 'k', 't');
        $paul->setPreference('push', ['social' => true, 'birthdays' => true, 'digest' => true]);
        $this->assertFalse($this->seesLandscape($paul), 'avant : seulement ses photos');

        $this->actingAs($this->owner)
            ->putJson("/albums/{$this->album->id}/share-with-tagged", ['enabled' => true])
            ->assertOk()
            ->assertJsonPath('share_with_tagged', true);

        $this->assertTrue($this->seesLandscape($paul));
        $this->assertTrue($this->album->fresh()->isAccessibleBy($paul));
        Notification::assertSentTo($paul, AlbumAccessGranted::class);

        // Identifiée APRÈS l'activation : l'album s'ouvre aussi.
        $marie = $this->taggedAccount($this->withPaul, 'Marie');
        $this->assertTrue($this->seesLandscape($marie));

        // Liste des accès : comptes que le propriétaire connaît (un cercle commun).
        $circle = \App\Models\Household::create(['name' => 'Amis', 'kind' => 'friends', 'invite_policy' => 'admin', 'created_by' => $this->owner->id]);
        $circle->addMember($this->owner, $this->owner);
        $circle->addMember($paul, $this->owner);
        $this->actingAs($this->owner)->getJson("/albums/{$this->album->id}/access")
            ->assertJsonFragment(['user_id' => $paul->id, 'origin' => 'tagged_shared']);

        // Désactivé : retour aux seules photos où l'on apparaît.
        $this->actingAs($this->owner)
            ->putJson("/albums/{$this->album->id}/share-with-tagged", ['enabled' => false])
            ->assertOk();
        $this->assertFalse($this->seesLandscape($paul));
    }

    public function test_une_association_automatique_non_verifiee_n_ouvre_rien(): void
    {
        $person = Person::factory()->create(['user_id' => $this->owner->id]);
        $auto = User::factory()->create(['person_id' => $person->id]);
        $this->withPaul->people()->attach($person->id);
        $this->withPaul->detectedFaces()->create([
            'person_id' => $person->id, 'status' => 'matched', 'provider' => 'faceapi',
            'bounding_box' => ['x' => 1, 'y' => 1, 'width' => 5, 'height' => 5], 'confidence' => 0.7,
        ]);
        $this->album->update(['share_with_tagged' => true]);

        $this->assertFalse($this->seesLandscape($auto));

        // Validée par un humain : l'album s'ouvre.
        $this->withPaul->detectedFaces()->update(['verified_at' => now()]);
        $this->assertTrue($this->seesLandscape($auto));
    }

    public function test_reserve_a_qui_peut_partager(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->putJson("/albums/{$this->album->id}/share-with-tagged", ['enabled' => true])
            ->assertForbidden();
        $this->assertFalse($this->album->fresh()->share_with_tagged);
    }
}
