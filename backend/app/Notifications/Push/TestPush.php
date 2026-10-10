<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Notification de test déclenchée depuis le profil : pas de catégorie, elle
 * part dès qu'un appareil est abonné (c'est le contrôleur qui le vérifie).
 */
class TestPush extends PushNotification
{
    public static function category(): string
    {
        return 'test';
    }

    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            'MemoryLane',
            'Les notifications fonctionnent sur cet appareil.',
            '/profile',
            'test',
            300,
        );
    }
}
