<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use App\Services\SmartAlbumService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmartAlbumTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
    }

    /**
     * Un album intelligent « personne » se remplit à la création.
     */
    public function test_smart_album_fills_with_person_media_on_creation(): void
    {
        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $withPerson = Media::factory()->count(2)->create(['user_id' => $this->user->id]);
        $withoutPerson = Media::factory()->create(['user_id' => $this->user->id]);
        $withPerson->each(fn ($m) => $m->people()->attach($person->id));

        $response = $this->actingAs($this->user)->post('/albums', [
            'name' => 'Photos de ' . $person->name,
            'is_smart' => true,
            'smart_rules' => ['person_id' => $person->id],
        ]);

        $response->assertRedirect();

        $album = Album::where('is_smart', true)->first();
        $this->assertNotNull($album);
        $this->assertEqualsCanonicalizing(
            $withPerson->pluck('id')->all(),
            $album->media()->pluck('media.id')->all()
        );
        $this->assertFalse($album->media()->whereKey($withoutPerson->id)->exists());
    }

    /**
     * Les règles se combinent en ET (année + type).
     */
    public function test_smart_album_combines_rules(): void
    {
        $match = Media::factory()->video()->create([
            'user_id' => $this->user->id,
            'taken_at' => '2024-07-14 12:00:00',
        ]);
        Media::factory()->video()->create([
            'user_id' => $this->user->id,
            'taken_at' => '2023-07-14 12:00:00', // mauvaise année
        ]);
        Media::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'photo',
            'taken_at' => '2024-08-01 12:00:00', // mauvais type
        ]);

        $album = Album::factory()->create([
            'user_id' => $this->user->id,
            'is_smart' => true,
            'smart_rules' => ['year' => 2024, 'type' => 'video'],
        ]);

        app(SmartAlbumService::class)->refresh($album);

        $this->assertEquals([$match->id], $album->media()->pluck('media.id')->all());
    }

    /**
     * Un nouveau média correspondant apparaît au refresh (visite de la page).
     */
    public function test_smart_album_refreshes_on_show(): void
    {
        $tag = Tag::create(['name' => 'Vacances', 'color' => '#0D9488']);
        $album = Album::factory()->create([
            'user_id' => $this->user->id,
            'is_smart' => true,
            'smart_rules' => ['tag_id' => $tag->id],
        ]);

        $this->assertEquals(0, $album->media()->count());

        $media = Media::factory()->create(['user_id' => $this->user->id]);
        $media->tags()->attach($tag->id);

        $this->actingAs($this->user)->get("/albums/{$album->id}")->assertStatus(200);

        $this->assertEquals([$media->id], $album->media()->pluck('media.id')->all());
    }

    /**
     * Un album classique n'est pas touché par le recalcul global.
     */
    public function test_regular_albums_are_untouched_by_refresh_all(): void
    {
        $album = Album::factory()->create(['user_id' => $this->user->id, 'is_smart' => false]);
        $media = Media::factory()->create(['user_id' => $this->user->id]);
        $album->media()->attach($media->id, ['order' => 1]);

        $this->artisan('memorylane:refresh-smart-albums')->assertSuccessful();

        $this->assertEquals([$media->id], $album->media()->pluck('media.id')->all());
    }

    /**
     * Un album intelligent sans aucune règle est refusé.
     */
    public function test_smart_album_requires_rules(): void
    {
        $response = $this->actingAs($this->user)->post('/albums', [
            'name' => 'Album vide',
            'is_smart' => true,
        ]);

        $response->assertSessionHasErrors('smart_rules');
    }
}
