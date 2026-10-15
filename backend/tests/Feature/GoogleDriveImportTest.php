<?php

namespace Tests\Feature;

use App\Jobs\ImportGoogleDriveFiles;
use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\GoogleOAuth;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoogleDriveImportTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    /** JPEG minimal valide. */
    protected string $jpeg;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Storage::fake('public');
        config(['services.google_drive.api_key' => 'browser-key', 'services.google_photos.client_id' => '123456789-abc.apps.googleusercontent.com']);
        $this->jpeg = base64_decode('/9j/4AAQSkZJRgABAQEAAAAAAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q==');
    }

    protected function driveToken(array $overrides = []): array
    {
        return ['google_drive.token' => array_merge([
            'access_token' => 'ya29.valid',
            'refresh_token' => '1//refresh',
            'expires_at' => now()->addHour()->timestamp,
        ], $overrides)];
    }

    public function test_page_exposes_picker_config_with_app_id_from_client_id(): void
    {
        $this->actingAs($this->user)
            ->get('/google-drive')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Media/GoogleDriveImport', false)
                ->where('isConfigured', true)
                ->where('isConnected', false)
                ->where('picker.apiKey', 'browser-key')
                ->where('picker.appId', '123456789')
            );
    }

    public function test_connect_requests_drive_file_scope_with_refresh_token(): void
    {
        $location = $this->actingAs($this->user)->get('/google-drive/connect')
            ->assertRedirect()
            ->headers->get('Location');

        $this->assertStringStartsWith(GoogleOAuth::AUTHORIZE_URL, $location);
        $this->assertStringContainsString(urlencode('https://www.googleapis.com/auth/drive.file'), $location);
        $this->assertStringContainsString('access_type=offline', $location);
    }

    /**
     * L'URI de retour est commune avec Google Photos : un state émis par
     * l'import Drive range le jeton côté Drive et ramène sur sa page.
     */
    public function test_shared_callback_routes_drive_state_to_drive(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'ya29.drive', 'refresh_token' => '1//r', 'expires_in' => 3599,
            ]),
        ]);

        $response = $this->actingAs($this->user)
            ->withSession(['google_drive.oauth_state' => 'drive-state', 'google_photos.oauth_state' => 'photos-state'])
            ->get('/auth/google/callback?code=auth-code&state=drive-state');

        $response->assertRedirect(route('google-drive.index'));
        $response->assertSessionHas('google_drive.token.access_token', 'ya29.drive');
        $response->assertSessionHas('google_drive.token.refresh_token', '1//r');
        $response->assertSessionMissing('google_photos.access_token');
    }

    public function test_token_endpoint_refreshes_an_expiring_token(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.new', 'expires_in' => 3599]),
        ]);

        $this->actingAs($this->user)
            ->withSession($this->driveToken(['access_token' => 'ya29.old', 'expires_at' => now()->addMinute()->timestamp]))
            ->postJson('/google-drive/token')
            ->assertOk()
            ->assertJson(['access_token' => 'ya29.new'])
            ->assertSessionHas('google_drive.token.refresh_token', '1//refresh');

        Http::assertSent(fn (HttpRequest $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === '1//refresh');
    }

    public function test_token_endpoint_requires_connection(): void
    {
        $this->actingAs($this->user)->postJson('/google-drive/token')->assertStatus(401);
    }

    public function test_import_dispatches_job_with_files_and_targets(): void
    {
        Bus::fake();

        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        $this->actingAs($this->user)
            ->withSession($this->driveToken())
            ->postJson('/google-drive/import', [
                'file_ids' => ['1AbC_d-E', '2XyZ', '1AbC_d-E'],
                'person_id' => $person->id,
                'album_id' => $album->id,
            ])
            ->assertOk()
            ->assertJsonStructure(['started_at']);

        Bus::assertDispatched(ImportGoogleDriveFiles::class, fn ($job) =>
            $job->userId === $this->user->id
            && $job->fileIds === ['1AbC_d-E', '2XyZ']
            && $job->accessToken === 'ya29.valid'
            && $job->refreshToken === '1//refresh'
            && $job->personId === $person->id
            && $job->albumId === $album->id
        );
    }

    public function test_import_rejects_malformed_ids_and_foreign_album(): void
    {
        Bus::fake();

        $foreignAlbum = Album::factory()->create(['user_id' => User::factory()->create()->id]);

        $this->actingAs($this->user)
            ->withSession($this->driveToken())
            ->postJson('/google-drive/import', [
                'file_ids' => ['../../etc/passwd'],
                'album_id' => $foreignAlbum->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file_ids.0', 'album_id']);

        Bus::assertNotDispatched(ImportGoogleDriveFiles::class);
    }

    public function test_import_requires_connection(): void
    {
        Bus::fake();

        $this->actingAs($this->user)
            ->postJson('/google-drive/import', ['file_ids' => ['abc']])
            ->assertStatus(401);

        Bus::assertNotDispatched(ImportGoogleDriveFiles::class);
    }

    public function test_job_imports_media_skips_other_types_and_attaches_targets(): void
    {
        Bus::fake(); // les jobs secondaires (conversions, EXIF) ne tournent pas

        $person = Person::factory()->create(['user_id' => $this->user->id]);
        $album = Album::factory()->create(['user_id' => $this->user->id]);

        Http::fake(function (HttpRequest $request) {
            $isDownload = str_contains($request->url(), 'alt=media');

            return match (true) {
                str_contains($request->url(), '/files/photo1') => $isDownload
                    ? Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg'])
                    : Http::response(['id' => 'photo1', 'name' => 'mariage-042.jpg', 'mimeType' => 'image/jpeg', 'size' => '123']),
                str_contains($request->url(), '/files/doc1') => Http::response(['id' => 'doc1', 'name' => 'Notes', 'mimeType' => 'application/vnd.google-apps.document']),
                default => Http::response([], 404),
            };
        });

        (new ImportGoogleDriveFiles(
            userId: $this->user->id,
            accessToken: 'ya29.valid',
            refreshToken: null,
            fileIds: ['photo1', 'doc1'],
            personId: $person->id,
            albumId: $album->id,
        ))->handle(app(MediaService::class), app(GoogleOAuth::class));

        $media = Media::where('original_name', 'mariage-042.jpg')->first();
        $this->assertNotNull($media);
        $this->assertEquals($this->user->id, $media->user_id);
        $this->assertTrue($media->people()->whereKey($person->id)->exists());
        $this->assertTrue($album->media()->whereKey($media->id)->exists());
        $this->assertEquals(1, Media::where('user_id', $this->user->id)->count());

        $this->actingAs($this->user)->getJson('/google-drive/imported')
            ->assertOk()
            ->assertJson(['finished' => true, 'failed' => false, 'skipped' => 1, 'count' => 1]);
    }

    public function test_import_shares_with_the_chosen_circle_only_if_member(): void
    {
        Bus::fake();
        $circle = \App\Models\Household::factory()->create(['created_by' => $this->user->id]);
        $circle->members()->attach($this->user->id);
        Http::fake(fn (HttpRequest $request) => str_contains($request->url(), 'alt=media')
            ? Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg'])
            : Http::response(['id' => 'p1', 'name' => 'grand-mere.jpg', 'mimeType' => 'image/jpeg', 'size' => '123']));

        (new ImportGoogleDriveFiles($this->user->id, 'ya29.valid', null, ['p1'], householdId: $circle->id))
            ->handle(app(MediaService::class), app(GoogleOAuth::class));
        $this->assertTrue(Media::where('original_name', 'grand-mere.jpg')->sole()->households()->whereKey($circle->id)->exists());

        $notMine = \App\Models\Household::factory()->create(['created_by' => User::factory()->create()->id]);
        $this->actingAs($this->user)->withSession($this->driveToken())
            ->postJson('/google-drive/import', ['file_ids' => ['p1'], 'share_household_id' => $notMine->id])
            ->assertForbidden();
    }

    /**
     * Jeton expiré en cours d'import : renouvelé une fois puis requête rejouée.
     */
    public function test_job_refreshes_expired_token(): void
    {
        Bus::fake();

        Http::fake(function (HttpRequest $request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'ya29.fresh', 'expires_in' => 3599]);
            }
            if (! $request->hasHeader('Authorization', 'Bearer ya29.fresh')) {
                return Http::response(['error' => ['code' => 401]], 401);
            }

            return str_contains($request->url(), 'alt=media')
                ? Http::response($this->jpeg, 200, ['Content-Type' => 'image/jpeg'])
                : Http::response(['id' => 'p1', 'name' => 'vieille-photo.jpg', 'mimeType' => 'image/jpeg', 'size' => '123']);
        });

        (new ImportGoogleDriveFiles(
            userId: $this->user->id,
            accessToken: 'ya29.expired',
            refreshToken: '1//refresh',
            fileIds: ['p1'],
        ))->handle(app(MediaService::class), app(GoogleOAuth::class));

        $this->assertTrue(Media::where('original_name', 'vieille-photo.jpg')->exists());
    }

    public function test_job_payload_is_encrypted_on_the_queue(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldBeEncrypted::class,
            new ImportGoogleDriveFiles($this->user->id, 'ya29.secret', '1//secret', ['x'])
        );
    }
}
