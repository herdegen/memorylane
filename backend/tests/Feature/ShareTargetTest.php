<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShareTargetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('public');
        Bus::fake();
    }

    /**
     * Un partage depuis le téléphone crée les médias et redirige vers la galerie.
     */
    public function test_shared_files_are_uploaded_and_redirect_to_gallery(): void
    {
        $response = $this->actingAs($this->user)->post('/share-target', [
            'media' => [
                UploadedFile::fake()->image('photo-1.jpg', 1200, 800),
                UploadedFile::fake()->image('photo-2.jpg', 800, 600),
            ],
        ]);

        $response->assertRedirect(route('media.index'));
        $response->assertSessionHas('success');

        $this->assertEquals(2, Media::count());
        $this->assertDatabaseHas('media', [
            'original_name' => 'photo-1.jpg',
            'user_id'       => $this->user->id,
        ]);
    }

    /**
     * Un partage vidéo est accepté.
     */
    public function test_shared_video_is_uploaded(): void
    {
        $response = $this->actingAs($this->user)->post('/share-target', [
            'media' => [
                UploadedFile::fake()->create('clip.mp4', 2048, 'video/mp4'),
            ],
        ]);

        $response->assertRedirect(route('media.index'));
        $this->assertDatabaseHas('media', [
            'original_name' => 'clip.mp4',
            'type'          => 'video',
        ]);
    }

    /**
     * Sans connexion, redirection vers le login (pas d'upload anonyme).
     */
    public function test_guest_share_is_redirected_to_login(): void
    {
        $response = $this->post('/share-target', [
            'media' => [UploadedFile::fake()->image('photo.jpg')],
        ]);

        $response->assertRedirect('/login');
        $this->assertEquals(0, Media::count());
    }

    /**
     * Les fichiers qui ne sont ni photo ni vidéo sont refusés.
     */
    public function test_non_media_files_are_rejected(): void
    {
        $response = $this->actingAs($this->user)->post('/share-target', [
            'media' => [
                UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertEquals(0, Media::count());
    }
}
