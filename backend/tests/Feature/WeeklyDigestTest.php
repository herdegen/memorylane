<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
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
     * Un média partagé via un album de cercle d'amis apparaît dans la lettre
     * amis des autres membres (qui remplace pour eux l'ancien résumé).
     */
    public function test_digest_includes_media_shared_through_a_circle_album(): void
    {
        Notification::fake();
        \Illuminate\Support\Facades\Mail::fake();

        $uploader = User::factory()->create(['name' => 'Paul Herdegen']);
        $grandma = User::factory()->create(['name' => 'Mamie Jeanne']);

        $media = Media::factory()->create([
            'user_id' => $uploader->id,
            'uploaded_at' => now()->subDays(2),
        ]);

        $album = \App\Models\Album::create([
            'user_id' => $uploader->id,
            'name' => 'Vacances',
        ]);
        $album->media()->attach($media->id);
        // Cercle d'amis : la lettre amis (cf. FriendsNewsletterTest ; la famille
        // reçoit la lettre famille, cf. FamilyNewsletterTest).
        $circle = \App\Models\Household::factory()->create(['created_by' => $uploader->id, 'kind' => \App\Models\Household::KIND_FRIENDS]);
        $circle->members()->attach([$uploader->id, $grandma->id]);
        $album->households()->attach($circle->id, ['granted_by' => $uploader->id]);

        $this->artisan('memorylane:weekly-digest')->assertSuccessful();

        \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\FriendsNewsletterMail::class, function ($mail) use ($grandma) {
            $photos = collect($mail->issue['sections'])->firstWhere('key', 'new_photos');

            return $mail->hasTo($grandma->email) && $photos['count'] === 1 && $photos['uploaders'] === ['Paul'];
        });
        Notification::assertNotSentTo($grandma, WeeklyDigest::class);
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

    /**
     * Canal push en plus du mail, uniquement pour ceux qui ont activé
     * « Résumé de la semaine » sur un appareil.
     */
    public function test_digest_part_aussi_en_push_quand_la_categorie_est_activee(): void
    {
        $user = User::factory()->create();
        $digest = new WeeklyDigest(34, ['Marie', 'Paul']);

        $this->assertSame(['mail'], $digest->via($user));

        $user->updatePushSubscription('https://push.example.org/u', 'k', 't');
        $user->setPreference('push', ['digest' => false]);
        $this->assertSame(['mail'], $digest->via($user));

        $user->setPreference('push.digest', true);
        $this->assertSame(['mail', WebPushChannel::class], $digest->via($user));

        $push = $digest->toWebPush($user, $digest)->toArray();
        $this->assertSame('34 nouveaux souvenirs (Marie, Paul)', $push['body']);
        $this->assertSame('/media', $push['data']['url']);
    }
}
