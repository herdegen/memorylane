<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sémantique de la corbeille :
 * - soft delete (galerie, doublons) : les fichiers S3 restent → restauration possible ;
 * - forceDelete : purge S3 (original + conversions) via l'event du modèle.
 */
class MediaTrashTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected string $disk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->disk = config('filesystems.default');
        Storage::fake($this->disk);
    }

    protected function makeMediaWithFiles(): Media
    {
        $media = Media::factory()->create([
            'user_id' => $this->user->id,
            'file_path' => 'photos/original.jpg',
        ]);
        $media->conversions()->create([
            'conversion_name' => 'small',
            'file_path' => 'conversions/original-small.jpg',
        ]);

        Storage::disk($this->disk)->put('photos/original.jpg', 'fake-original');
        Storage::disk($this->disk)->put('conversions/original-small.jpg', 'fake-thumb');

        return $media;
    }

    public function test_soft_delete_keeps_storage_files(): void
    {
        $media = $this->makeMediaWithFiles();

        $this->actingAs($this->user)
            ->deleteJson("/media/{$media->id}")
            ->assertStatus(200);

        $this->assertSoftDeleted('media', ['id' => $media->id]);
        Storage::disk($this->disk)->assertExists('photos/original.jpg');
        Storage::disk($this->disk)->assertExists('conversions/original-small.jpg');
    }

    public function test_force_delete_purges_storage_files(): void
    {
        $media = $this->makeMediaWithFiles();

        $media->forceDelete();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk($this->disk)->assertMissing('photos/original.jpg');
        Storage::disk($this->disk)->assertMissing('conversions/original-small.jpg');
    }

    public function test_cannot_delete_someone_elses_media(): void
    {
        $media = $this->makeMediaWithFiles();
        $other = User::factory()->create();

        $this->actingAs($other)
            ->deleteJson("/media/{$media->id}")
            ->assertStatus(403);

        $this->assertNotSoftDeleted('media', ['id' => $media->id]);
    }
}
