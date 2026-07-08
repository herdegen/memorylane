<?php

namespace Tests\Feature;

use App\Jobs\ImportGooglePhotosItems;
use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GooglePhotosImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('public');
    }

    protected function withGoogleSession(array $extra = []): array
    {
        return array_merge([
            'google_photos.access_token' => 'fake-token',
            'google_photos.picker_session' => ['id' => 'sessions/abc123', 'pickerUri' => 'https://photos.google.com/picker/abc'],
        ], $extra);
    }

    public function test_imported_status_reflects_terminal_marker(): void
    {
        // Aucun marqueur → import non terminé.
        $this->actingAs($this->user)->getJson('/google-photos/imported')
            ->assertOk()
            ->assertJson(['finished' => false, 'failed' => false]);

        // Marqueur de succès posé par le job.
        Cache::put(ImportGooglePhotosItems::statusKey($this->user->id), [
            'finished' => true, 'failed' => false,
        ], now()->addHour());

        $this->actingAs($this->user)->getJson('/google-photos/imported')
            ->assertOk()
            ->assertJson(['finished' => true, 'failed' => false]);
    }

    /**
     * La connexion redirige vers le consentement Google avec le bon scope.
     */
    public function test_connect_redirects_to_google_consent(): void
    {
        $response = $this->actingAs($this->user)->get('/google-photos/connect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth', $location);
        $this->assertStringContainsString('photospicker.mediaitems.readonly', $location);
    }

    /**
     * Le callback avec un state invalide est rejeté sans appel à Google.
     */
    public function test_callback_rejects_invalid_state(): void
    {
        Http::fake();

        $response = $this->actingAs($this->user)
            ->withSession(['google_photos.oauth_state' => 'expected'])
            ->get('/auth/google/callback?code=x&state=wrong');

        $response->assertRedirect(route('google-photos.index'));
        $response->assertSessionHasErrors('google');
        Http::assertNothingSent();
    }

    /**
     * Un callback valide stocke le token en session.
     */
    public function test_callback_exchanges_code_for_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token']),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['google_photos.oauth_state' => 'state123'])
            ->get('/auth/google/callback?code=auth-code&state=state123');

        $response->assertRedirect(route('google-photos.index'));
        $response->assertSessionHas('google_photos.access_token', 'ya29.token');
    }

    /**
     * L'import dispatche le job avec la personne et l'album choisis.
     */
    public function test_import_dispatches_job_with_targets(): void
    {
        Bus::fake();

        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->withSession($this->withGoogleSession())
            ->post('/google-photos/import', [
                'person_id' => $person->id,
                'album_id' => $album->id,
            ]);

        $response->assertRedirect(route('media.index'));
        Bus::assertDispatched(ImportGooglePhotosItems::class, fn ($job) =>
            $job->userId === $this->user->id
            && $job->pickerSessionId === 'sessions/abc123'
            && $job->personId === $person->id
            && $job->albumId === $album->id
        );
    }

    /**
     * Sans session Picker, l'import est refusé.
     */
    public function test_import_requires_picker_session(): void
    {
        Bus::fake();

        $response = $this->actingAs($this->user)->post('/google-photos/import');

        $response->assertStatus(409);
        Bus::assertNotDispatched(ImportGooglePhotosItems::class);
    }

    /**
     * Le job télécharge les éléments, crée les médias et les rattache.
     */
    public function test_job_deduplicates_by_content_hash(): void
    {
        Bus::fake();

        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
        $hash = hash('sha256', $jpeg);

        // Média déjà présent avec ce contenu (nom différent) → l'import doit le
        // détecter par empreinte et ne PAS créer de doublon.
        Media::factory()->create([
            'user_id' => $this->user->id,
            'type' => 'photo',
            'original_name' => 'ancien-nom.jpg',
            'content_hash' => $hash,
        ]);

        Http::fake([
            'photospicker.googleapis.com/v1/mediaItems*' => Http::response([
                'mediaItems' => [[
                    'id' => 'item-1',
                    'mediaFile' => [
                        'baseUrl' => 'https://lh3.googleusercontent.com/fake',
                        'filename' => 'nouveau-nom.jpg',
                        'mimeType' => 'image/jpeg',
                    ],
                ]],
            ]),
            'lh3.googleusercontent.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'photospicker.googleapis.com/v1/sessions/*' => Http::response([]),
        ]);

        (new ImportGooglePhotosItems(
            userId: $this->user->id,
            accessToken: 'fake-token',
            pickerSessionId: 'sessions/abc123',
            personId: null,
            albumId: null,
        ))->handle(app(\App\Services\MediaService::class));

        // Aucun nouveau média : le doublon de contenu a été ignoré.
        $this->assertFalse(Media::where('original_name', 'nouveau-nom.jpg')->exists());
        $this->assertEquals(1, Media::where('user_id', $this->user->id)->count());
    }

    public function test_job_imports_items_and_attaches_targets(): void
    {
        Bus::fake(); // les jobs secondaires (conversions, EXIF) ne tournent pas

        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        // Un vrai contenu JPEG minimal pour le téléchargement simulé
        $jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');

        Http::fake([
            'photospicker.googleapis.com/v1/mediaItems*' => Http::response([
                'mediaItems' => [
                    [
                        'id' => 'item-1',
                        'mediaFile' => [
                            'baseUrl' => 'https://lh3.googleusercontent.com/fake-base-url',
                            'filename' => 'plage-1998.jpg',
                            'mimeType' => 'image/jpeg',
                        ],
                    ],
                ],
            ]),
            'lh3.googleusercontent.com/*' => Http::response($jpeg, 200, ['Content-Type' => 'image/jpeg']),
            'photospicker.googleapis.com/v1/sessions/*' => Http::response([]),
        ]);

        $job = new ImportGooglePhotosItems(
            userId: $this->user->id,
            accessToken: 'fake-token',
            pickerSessionId: 'sessions/abc123',
            personId: $person->id,
            albumId: $album->id,
        );
        $job->handle(app(\App\Services\MediaService::class));

        $media = Media::where('original_name', 'plage-1998.jpg')->first();
        $this->assertNotNull($media);
        $this->assertEquals($this->user->id, $media->user_id);
        $this->assertTrue($media->people()->whereKey($person->id)->exists());
        $this->assertTrue($album->media()->whereKey($media->id)->exists());
    }
}
