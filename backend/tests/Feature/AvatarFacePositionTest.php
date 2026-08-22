<?php

namespace Tests\Feature;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\Vision\AvatarFacePositionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cadrage intelligent des avatars « photo entière » (issue #51) :
 * object-position centré sur le visage détecté de la personne sur SA photo
 * d'avatar, calculé en lot par AvatarFacePositionService.
 */
class AvatarFacePositionTest extends TestCase
{
    use RefreshDatabase;

    private function personWithAvatar(User $user): array
    {
        $media = Media::factory()->photo()->create(['user_id' => $user->id]);
        $person = Person::factory()->create(['user_id' => $user->id, 'avatar_media_id' => $media->id]);

        return [$person, $media];
    }

    private function face(Media $media, ?Person $person, array $box, float $confidence = 0.9, string $status = 'matched'): DetectedFace
    {
        return DetectedFace::create([
            'media_id' => $media->id,
            'person_id' => $person?->id,
            'bounding_box' => $box,
            'confidence' => $confidence,
            'status' => $status,
        ]);
    }

    public function test_position_au_centre_du_visage(): void
    {
        $user = User::factory()->create();
        [$person, $media] = $this->personWithAvatar($user);

        // Boîte : x 60 %, y 10 %, 20×20 % → centre (70 %, 20 %).
        $this->face($media, $person, ['x' => 60, 'y' => 10, 'width' => 20, 'height' => 20]);

        $positions = app(AvatarFacePositionService::class)->forPeople(collect([$person]));

        $this->assertSame('70% 20%', $positions[$person->id]);
    }

    public function test_prend_le_visage_le_plus_confiant_de_la_bonne_personne(): void
    {
        $user = User::factory()->create();
        [$person, $media] = $this->personWithAvatar($user);
        $other = Person::factory()->create(['user_id' => $user->id]);

        // Visage d'une AUTRE personne sur la même photo → ignoré.
        $this->face($media, $other, ['x' => 0, 'y' => 0, 'width' => 10, 'height' => 10], 0.99);
        // Deux visages de la personne : le plus confiant gagne.
        $this->face($media, $person, ['x' => 40, 'y' => 40, 'width' => 20, 'height' => 20], 0.95);
        $this->face($media, $person, ['x' => 80, 'y' => 80, 'width' => 10, 'height' => 10], 0.50);

        $positions = app(AvatarFacePositionService::class)->forPeople(collect([$person, $other]));

        $this->assertSame('50% 50%', $positions[$person->id]);
        $this->assertArrayNotHasKey($other->id, $positions);
    }

    public function test_ignore_les_visages_non_confirmes_et_les_autres_photos(): void
    {
        $user = User::factory()->create();
        [$person, $media] = $this->personWithAvatar($user);
        $otherMedia = Media::factory()->photo()->create(['user_id' => $user->id]);

        // Visage en attente (pas « matched ») sur l'avatar → ignoré.
        $this->face($media, $person, ['x' => 10, 'y' => 10, 'width' => 10, 'height' => 10], 0.9, 'pending');
        // Visage confirmé mais sur une AUTRE photo que l'avatar → ignoré.
        $this->face($otherMedia, $person, ['x' => 10, 'y' => 10, 'width' => 10, 'height' => 10]);

        $positions = app(AvatarFacePositionService::class)->forPeople(collect([$person]));

        $this->assertSame([], $positions);
    }

    public function test_personne_sans_avatar_explicite_ignoree(): void
    {
        $user = User::factory()->create();
        $person = Person::factory()->create(['user_id' => $user->id, 'avatar_media_id' => null]);

        $positions = app(AvatarFacePositionService::class)->forPeople(collect([$person, null]));

        $this->assertSame([], $positions);
    }

    public function test_la_fiche_personne_expose_avatar_position(): void
    {
        $user = User::factory()->create();
        [$person, $media] = $this->personWithAvatar($user);
        $this->face($media, $person, ['x' => 20, 'y' => 0, 'width' => 20, 'height' => 20]);

        $response = $this->actingAs($user)->get("/people/{$person->id}");

        $response->assertStatus(200);
        $this->assertSame('30% 10%', $response->viewData('page')['props']['person']['avatar_position']);
    }

    public function test_l_arbre_expose_avatar_position(): void
    {
        $user = User::factory()->create();
        [$person, $media] = $this->personWithAvatar($user);
        $this->face($media, $person, ['x' => 20, 'y' => 0, 'width' => 20, 'height' => 20]);

        $response = $this->actingAs($user)->getJson('/family-tree/data');

        $response->assertStatus(200);
        $node = collect($response->json())->firstWhere('id', $person->id);
        $this->assertSame('30% 10%', $node['data']['avatar_position']);
    }
}
