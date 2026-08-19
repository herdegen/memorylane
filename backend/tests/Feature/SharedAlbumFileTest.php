<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fichiers des albums partagés par lien secret : le token vaut autorisation,
 * mais UNIQUEMENT pour les médias de cet album.
 */
class SharedAlbumFileTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Album $album;

    protected Media $inAlbum;

    protected Media $outside;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->owner = User::factory()->create();
        $this->album = Album::factory()->create([
            'user_id' => $this->owner->id,
            'share_token' => 'token-secret-123',
        ]);
        $this->inAlbum = Media::factory()->create(['user_id' => $this->owner->id, 'file_path' => 'photos/in.jpg']);
        $this->outside = Media::factory()->create(['user_id' => $this->owner->id, 'file_path' => 'photos/out.jpg']);
        $this->album->media()->attach($this->inAlbum->id);

        Storage::disk(config('filesystems.default'))->put('photos/in.jpg', 'x');
        Storage::disk(config('filesystems.default'))->put('photos/out.jpg', 'x');
    }

    public function test_anonymous_visitor_with_token_can_load_album_media(): void
    {
        $response = $this->get("/albums/shared/token-secret-123/media/{$this->inAlbum->id}/file");

        $response->assertStatus(302);
        $this->assertStringNotContainsString('/login', $response->headers->get('Location'));
    }

    public function test_token_does_not_unlock_media_outside_the_album(): void
    {
        $this->get("/albums/shared/token-secret-123/media/{$this->outside->id}/file")
            ->assertStatus(404);
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->get("/albums/shared/mauvais-token/media/{$this->inAlbum->id}/file")
            ->assertStatus(404);
    }

    public function test_shared_page_hydrates_media_with_token_urls(): void
    {
        $response = $this->get('/albums/shared/token-secret-123');

        $response->assertStatus(200)
            ->assertInertia(fn ($page) => $page
                ->where('album.media.0.url', fn ($url) => str_contains(
                    $url,
                    "/albums/shared/token-secret-123/media/{$this->inAlbum->id}/file"
                ))
            );
    }
}
