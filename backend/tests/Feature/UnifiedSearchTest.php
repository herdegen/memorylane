<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnifiedSearchTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * La recherche retourne les quatre familles de résultats.
     */
    public function test_search_returns_grouped_results(): void
    {
        $media = Media::factory()->create([
            'user_id'       => $this->user->id,
            'original_name' => 'vacances-bretagne.jpg',
        ]);
        $person = Person::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Marie Vacances',
        ]);
        $album = Album::factory()->create([
            'user_id' => $this->user->id,
            'name'    => 'Vacances 1998',
        ]);
        $tag = Tag::create(['name' => 'Vacances', 'color' => '#0D9488']);

        $response = $this->actingAs($this->user)->getJson('/search?q=Vacances');

        $response->assertStatus(200)
            ->assertJsonPath('media.0.id', $media->id)
            ->assertJsonPath('people.0.id', $person->id)
            ->assertJsonPath('albums.0.id', $album->id)
            ->assertJsonPath('tags.0.id', $tag->id);
    }

    /**
     * La recherche trouve aussi par titre de média.
     */
    public function test_search_finds_media_by_title(): void
    {
        $media = Media::factory()->create([
            'user_id'       => $this->user->id,
            'original_name' => 'IMG_0042.jpg',
            'title'         => 'Anniversaire de Mamie',
        ]);

        $response = $this->actingAs($this->user)->getJson('/search?q=Anniversaire');

        $response->assertStatus(200)
            ->assertJsonPath('media.0.id', $media->id);
    }

    /**
     * Sans correspondance, les groupes sont vides.
     */
    public function test_search_returns_empty_groups_when_nothing_matches(): void
    {
        Media::factory()->create([
            'user_id'       => $this->user->id,
            'original_name' => 'plage.jpg',
        ]);

        $response = $this->actingAs($this->user)->getJson('/search?q=montagne');

        $response->assertStatus(200)
            ->assertJsonCount(0, 'media')
            ->assertJsonCount(0, 'people')
            ->assertJsonCount(0, 'albums')
            ->assertJsonCount(0, 'tags');
    }

    /**
     * Une requête trop courte est rejetée.
     */
    public function test_search_requires_at_least_two_characters(): void
    {
        $response = $this->actingAs($this->user)->getJson('/search?q=a');

        $response->assertStatus(422);
    }

    /**
     * La recherche exige d'être connecté.
     */
    public function test_search_requires_authentication(): void
    {
        $response = $this->getJson('/search?q=vacances');

        $response->assertStatus(401);
    }
}
