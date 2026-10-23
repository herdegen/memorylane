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
        return $this->message(
            __('« :name »', ['name' => $this->householdName]),
            trans_choice(':name a partagé un souvenir avec le cercle.|:name a partagé :count souvenirs avec le cercle.', $this->count, ['name' => $this->actorFirstName]),
            "/households/{$this->householdId}",
            "household-share-{$this->householdId}",
        )->renotify();
    }
}
