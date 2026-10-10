<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * « Marie a partagé 37 souvenirs avec le foyer « X » » — aux autres membres.
 * Toujours regroupée par NotifyHouseholdShare (fenêtre de 10 min).
 */
class MediaSharedToHousehold extends PushNotification
{
    public function __construct(
        public string $actorFirstName,
        public string $householdName,
        public string $householdId,
        public int $count,
    ) {}

    public static function category(): string
    {
        return 'social';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $what = $this->count === 1 ? 'un souvenir' : "{$this->count} souvenirs";

        return $this->message(
            "Foyer « {$this->householdName} »",
            "{$this->actorFirstName} a partagé {$what} avec le foyer.",
            "/households/{$this->householdId}",
            "household-share-{$this->householdId}",
        )->renotify();
    }
}
