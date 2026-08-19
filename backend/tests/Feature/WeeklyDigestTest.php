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
     * Le digest ne contient pour chaque destinataire que les médias
     * auxquels il a accès : les médias privés ne partent qu'à leur
     * propriétaire.
     */
    public function test_digest_only_includes_media_accessible_to_recipient(): void
    {
        Notification::fake();

        $uploader = User::factory()->create(['name' => 'Paul Herdegen']);
        $grandma = User::factory()->create(['name' => 'Mamie Jeanne']);

        Media::factory()->count(3)->create([
            'user_id' => $uploader->id,
            'uploaded_at' => now()->subDays(2),
        ]);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        Notification::assertSentTo($uploader, WeeklyDigest::class, function (WeeklyDigest $digest) {
            return $digest->mediaCount === 3
                && in_array('Paul', $digest->uploaderNames);
        });

        // Les médias sont privés : Mamie ne reçoit rien.
        Notification::assertNotSentTo($grandma, WeeklyDigest::class);
    }

    /**
     * Un média partagé via un album public apparaît dans le digest des autres.
     */
    public function test_digest_includes_media_shared_through_public_album(): void
    {
        Notification::fake();

        $uploader = User::factory()->create(['name' => 'Paul Herdegen']);
        $grandma = User::factory()->create(['name' => 'Mamie Jeanne']);

        $media = Media::factory()->create([
            'user_id' => $uploader->id,
            'uploaded_at' => now()->subDays(2),
        ]);

        $album = \App\Models\Album::create([
            'user_id' => $uploader->id,
            'name' => 'Vacances',
            'is_public' => true,
        ]);
        $album->media()->attach($media->id);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        Notification::assertSentTo($grandma, WeeklyDigest::class, function (WeeklyDigest $digest) {
            return $digest->mediaCount === 1
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
