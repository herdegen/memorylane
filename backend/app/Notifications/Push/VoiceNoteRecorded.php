<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * « Jeanne a raconté une photo » — au propriétaire de la photo et aux
 * personnes présentes dessus, pour aller écouter le mémo vocal.
 */
class VoiceNoteRecorded extends PushNotification
{
    public function __construct(
        public string $narratorFirstName,
        public string $mediaId,
    ) {}

    public static function category(): string
    {
        return 'social';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            'Un souvenir raconté',
            "{$this->narratorFirstName} a raconté une photo. Allez l'écouter !",
            "/media/{$this->mediaId}",
            "voice-note-{$this->mediaId}",
        );
    }
}
