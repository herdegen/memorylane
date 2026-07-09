<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\UploadSession;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DirectUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();

        // On ne touche jamais Scaleway : le client S3 est simulé.
        $mock = $this->mock(S3Service::class);
        $mock->shouldReceive('generateFilePath')->andReturn('media/videos/2026/07/abc.mp4');
        $mock->shouldReceive('createMultipartUpload')->andReturn('upload-id-123');
        $mock->shouldReceive('presignUploadPart')->andReturn('https://s3.example/part?sig=x');
        $mock->shouldReceive('completeMultipartUpload')->andReturnNull();
        $mock->shouldReceive('abortMultipartUpload')->andReturnNull();
        $mock->shouldReceive('listUploadedParts')->andReturn([
            ['part_number' => 1, 'etag' => '"aaa"'],
        ]);
        $mock->shouldReceive('getTemporaryUrl')->andReturn('https://s3.example/signed');
    }

    public function test_initiate_creates_session(): void
    {
        $response = $this->actingAs($this->user)->postJson('/media/uploads/initiate', [
            'original_name' => 'famille.mp4',
            'mime_type'     => 'video/mp4',
            'size'          => 500 * 1024 * 1024, // 500 Mo
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['upload_session_id', 'part_size', 'part_count']);

        $this->assertDatabaseHas('upload_sessions', [
            'user_id'   => $this->user->id,
            'upload_id' => 'upload-id-123',
            'type'      => 'video',
        ]);
    }

    public function test_initiate_rejects_disallowed_mime(): void
    {
        $this->actingAs($this->user)->postJson('/media/uploads/initiate', [
            'original_name' => 'malware.exe',
            'mime_type'     => 'application/x-msdownload',
            'size'          => 1000,
        ])->assertStatus(422)->assertJsonValidationErrors(['mime_type']);
    }

    public function test_initiate_rejects_oversized_file(): void
    {
        $this->actingAs($this->user)->postJson('/media/uploads/initiate', [
            'original_name' => 'huge.mp4',
            'mime_type'     => 'video/mp4',
            'size'          => 50 * 1024 * 1024 * 1024, // 50 Go > plafond
        ])->assertStatus(422)->assertJsonValidationErrors(['size']);
    }

    public function test_part_url_requires_ownership(): void
    {
        $session = UploadSession::create([
            'user_id' => $this->otherUser->id,
            'upload_id' => 'u1', 's3_key' => 'media/videos/x.mp4',
            'original_name' => 'x.mp4', 'mime_type' => 'video/mp4',
            'size' => 1000, 'type' => 'video',
        ]);

        $this->actingAs($this->user)->postJson('/media/uploads/part-url', [
            'upload_session_id' => $session->id,
            'part_number' => 1,
        ])->assertStatus(404);
    }

    public function test_part_url_returns_signed_url(): void
    {
        $session = UploadSession::create([
            'user_id' => $this->user->id,
            'upload_id' => 'u1', 's3_key' => 'media/videos/x.mp4',
            'original_name' => 'x.mp4', 'mime_type' => 'video/mp4',
            'size' => 1000, 'type' => 'video',
        ]);

        $this->actingAs($this->user)->postJson('/media/uploads/part-url', [
            'upload_session_id' => $session->id,
            'part_number' => 1,
        ])->assertStatus(200)->assertJsonStructure(['url']);
    }

    public function test_complete_creates_media_and_clears_session(): void
    {
        Queue::fake();

        $session = UploadSession::create([
            'user_id' => $this->user->id,
            'upload_id' => 'u1', 's3_key' => 'media/videos/x.mp4',
            'original_name' => 'famille.mp4', 'mime_type' => 'video/mp4',
            'size' => 1234, 'type' => 'video',
        ]);

        $response = $this->actingAs($this->user)->postJson('/media/uploads/complete', [
            'upload_session_id' => $session->id,
            'parts' => [
                ['part_number' => 1, 'etag' => '"aaa"'],
                ['part_number' => 2, 'etag' => '"bbb"'],
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('media.type', 'video');

        $this->assertDatabaseMissing('upload_sessions', ['id' => $session->id]);
        $this->assertDatabaseHas('media', [
            'user_id'   => $this->user->id,
            'file_path' => 'media/videos/x.mp4',
            'type'      => 'video',
            'original_name' => 'famille.mp4',
        ]);
    }

    public function test_status_returns_uploaded_parts_for_resume(): void
    {
        $session = UploadSession::create([
            'user_id' => $this->user->id,
            'upload_id' => 'u1', 's3_key' => 'media/videos/x.mp4',
            'original_name' => 'x.mp4', 'mime_type' => 'video/mp4',
            'size' => 300 * 1024 * 1024, 'type' => 'video',
        ]);

        $response = $this->actingAs($this->user)->postJson('/media/uploads/status', [
            'upload_session_id' => $session->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('uploaded_parts.0.part_number', 1)
            ->assertJsonStructure(['part_size', 'part_count', 'uploaded_parts']);
    }

    public function test_status_requires_ownership(): void
    {
        $session = UploadSession::create([
            'user_id' => $this->otherUser->id,
            'upload_id' => 'u1', 's3_key' => 'media/videos/x.mp4',
            'original_name' => 'x.mp4', 'mime_type' => 'video/mp4',
            'size' => 1000, 'type' => 'video',
        ]);

        $this->actingAs($this->user)->postJson('/media/uploads/status', [
            'upload_session_id' => $session->id,
        ])->assertStatus(404);
    }

    public function test_abort_deletes_session(): void
    {
        $session = UploadSession::create([
            'user_id' => $this->user->id,
            'upload_id' => 'u1', 's3_key' => 'media/videos/x.mp4',
            'original_name' => 'x.mp4', 'mime_type' => 'video/mp4',
            'size' => 1000, 'type' => 'video',
        ]);

        $this->actingAs($this->user)->postJson('/media/uploads/abort', [
            'upload_session_id' => $session->id,
        ])->assertStatus(200);

        $this->assertDatabaseMissing('upload_sessions', ['id' => $session->id]);
    }
}
