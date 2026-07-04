<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque membre reçoit le digest quand il y a du nouveau.
     */
    public function test_digest_is_sent_to_all_users_when_new_media_exist(): void
    {
        Notification::fake();

        $uploader = User::factory()->create(['name' => 'Paul Herdegen']);
        $grandma = User::factory()->create(['name' => 'Mamie Jeanne']);

        Media::factory()->count(3)->create([
            'user_id' => $uploader->id,
            'uploaded_at' => now()->subDays(2),
        ]);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        Notification::assertSentTo($uploader, WeeklyDigest::class);
        Notification::assertSentTo($grandma, WeeklyDigest::class, function (WeeklyDigest $digest) {
            return $digest->mediaCount === 3
                && in_array('Paul', $digest->uploaderNames);
        });
    }

    /**
     * Semaine sans nouveauté : personne n'est dérangé.
     */
    public function test_no_digest_when_nothing_new(): void
    {
        Notification::fake();

        $user = User::factory()->create();
        Media::factory()->create([
            'user_id' => $user->id,
            'uploaded_at' => now()->subDays(30), // trop vieux
        ]);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /**
     * Le mail contient le compte et le lien vers la galerie.
     */
    public function test_digest_mail_content(): void
    {
        $user = User::factory()->create(['name' => 'Mamie Jeanne']);

        $digest = new WeeklyDigest(5, ['Paul', 'Marie'], [
            ['name' => 'plage.jpg', 'url' => 'https://example.com/thumb.jpg'],
        ]);

        $mail = $digest->toMail($user);

        $this->assertEquals('Cette semaine sur MemoryLane', $mail->subject);
        $rendered = $mail->render()->__toString();
        $this->assertStringContainsString('5 nouveaux souvenirs', $rendered);
        $this->assertStringContainsString('Paul, Marie', $rendered);
        $this->assertStringContainsString('Voir les nouveaut', $rendered);
    }
}
