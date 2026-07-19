<?php

namespace Tests\Feature;

use App\Models\UploadSession;
use App\Models\User;
use App\Services\S3Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneUploadSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeSession(User $user, string $key, string $uploadId): UploadSession
    {
        return UploadSession::create([
            'user_id'       => $user->id,
            'upload_id'     => $uploadId,
            's3_key'        => $key,
            'original_name' => 'video.mp4',
            'mime_type'     => 'video/mp4',
            'size'          => 1_000_000,
            'type'          => 'video',
        ]);
    }

    public function test_prunes_abandoned_sessions_and_aborts_them_on_s3(): void
    {
        $user = User::factory()->create();

        $old = $this->makeSession($user, 'media/old.mp4', 'upload-old');
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $recent = $this->makeSession($user, 'media/recent.mp4', 'upload-recent');

        $mock = $this->mock(S3Service::class);
        $mock->shouldReceive('abortMultipartUpload')
            ->once()
            ->with('media/old.mp4', 'upload-old');

        $this->artisan('memorylane:prune-upload-sessions')->assertSuccessful();

        // La session abandonnée est supprimée, la récente conservée.
        $this->assertDatabaseMissing('upload_sessions', ['id' => $old->id]);
        $this->assertDatabaseHas('upload_sessions', ['id' => $recent->id]);
    }

    public function test_deletes_session_even_if_s3_abort_fails(): void
    {
        $user = User::factory()->create();

        $old = $this->makeSession($user, 'media/gone.mp4', 'upload-gone');
        $old->forceFill(['created_at' => now()->subDays(3)])->save();

        $mock = $this->mock(S3Service::class);
        $mock->shouldReceive('abortMultipartUpload')
            ->andThrow(new \RuntimeException('upload déjà expiré'));

        $this->artisan('memorylane:prune-upload-sessions')->assertSuccessful();

        // Malgré l'échec S3, la session ne doit pas rester bloquée.
        $this->assertDatabaseMissing('upload_sessions', ['id' => $old->id]);
    }

    public function test_hours_option_controls_the_cutoff(): void
    {
        $user = User::factory()->create();

        $session = $this->makeSession($user, 'media/6h.mp4', 'upload-6h');
        $session->forceFill(['created_at' => now()->subHours(6)])->save();

        // Seuil par défaut (48h) : la session de 6h est conservée.
        $this->mock(S3Service::class)->shouldReceive('abortMultipartUpload')->never();
        $this->artisan('memorylane:prune-upload-sessions')->assertSuccessful();
        $this->assertDatabaseHas('upload_sessions', ['id' => $session->id]);

        // Seuil abaissé à 1h : elle est nettoyée.
        $this->mock(S3Service::class)->shouldReceive('abortMultipartUpload')->once();
        $this->artisan('memorylane:prune-upload-sessions', ['--hours' => 1])->assertSuccessful();
        $this->assertDatabaseMissing('upload_sessions', ['id' => $session->id]);
    }
}
