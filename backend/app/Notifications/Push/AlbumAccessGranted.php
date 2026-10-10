<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** « Marie vous a donné accès à l'album « Y » » — au bénéficiaire. */
class AlbumAccessGranted extends PushNotification
{
    public function __construct(
        public string $actorFirstName,
        public string $albumName,
        public string $albumId,
    ) {}

    public static function category(): string
    {
        return 'social';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            'Album partagé',
            "{$this->actorFirstName} vous a donné accès à l'album « {$this->albumName} ».",
            "/albums/{$this->albumId}",
            "album-access-{$this->albumId}",
        );
    }
}
