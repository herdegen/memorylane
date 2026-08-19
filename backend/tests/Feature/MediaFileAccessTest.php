<?php

namespace Tests\Feature;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Protection des images : les fichiers ne sont plus exposés en présigné long
 * dans les pages — tout passe par /media/{media}/file/{conversion?} qui
 * vérifie session + policy à CHAQUE chargement puis redirige vers une
 * présignée S3 très courte. Une URL copiée-collée est inutilisable sans
 * être connecté.
 */
class MediaFileAccessTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default'));

        $this->owner = User::factory()->create();
        $this->media = Media::factory()->create([
            'user_id' => $this->owner->id,
            'file_path' => 'photos/secret.jpg',
        ]);
        $this->media->conversions()->create([
            'conversion_name' => 'small',
            'file_path' => 'conversions/secret-small.jpg',
        ]);

        Storage::disk(config('filesystems.default'))->put('photos/secret.jpg', 'x');
        Storage::disk(config('filesystems.default'))->put('conversions/secret-small.jpg', 'x');
    }

    public function test_guest_cannot_load_media_file_url(): void
    {
        $this->get("/media/{$this->media->id}/file")
            ->assertRedirect('/login');

        $this->get("/media/{$this->media->id}/file/small")
            ->assertRedirect('/login');
    }

    public function test_owner_gets_short_lived_redirect(): void
    {
        $response = $this->actingAs($this->owner)
            ->get("/media/{$this->media->id}/file");

        $response->assertStatus(302);
        $this->assertStringNotContainsString('/login', $response->headers->get('Location'));
    }

    public function test_conversion_variant_and_unknown_conversion(): void
    {
        $this->actingAs($this->owner)
            ->get("/media/{$this->media->id}/file/small")
            ->assertStatus(302);

        $this->actingAs($this->owner)
            ->get("/media/{$this->media->id}/file/inexistante")
            ->assertStatus(404);
    }

    public function test_non_owner_without_access_gets_403(): void
    {
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->get("/media/{$this->media->id}/file")
            ->assertStatus(403);

        $this->actingAs($intruder)
            ->get("/media/{$this->media->id}/file/small")
            ->assertStatus(403);
    }

    public function test_user_with_album_access_can_load_file(): void
    {
        $other = User::factory()->create();
        $album = Album::factory()->create([
            'user_id' => $this->owner->id,
            'is_public' => true,
        ]);
        $album->media()->attach($this->media->id);

        $this->actingAs($other)
            ->get("/media/{$this->media->id}/file")
            ->assertStatus(302);
    }

    public function test_admin_can_load_any_media_file(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get("/media/{$this->media->id}/file")
            ->assertStatus(302);
    }

    public function test_hydrated_urls_point_to_protected_route_not_s3(): void
    {
        $this->media->load('conversions');
        app(MediaService::class)->hydrateSignedUrls([$this->media]);

        $this->assertStringContainsString("/media/{$this->media->id}/file", $this->media->url);
        $this->assertStringContainsString(
            "/media/{$this->media->id}/file/small",
            $this->media->conversions->first()->url
        );
        // Aucune trace du chemin S3 réel dans les URLs exposées
        $this->assertStringNotContainsString('secret.jpg', $this->media->url);
    }
}
