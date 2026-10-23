<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** « Vous faites maintenant partie du foyer « X » » — à la personne ajoutée. */
class HouseholdMemberAdded extends PushNotification
{
    public function __construct(
        public string $actorFirstName,
        public string $householdName,
        public string $householdId,
    ) {}

    public static function category(): string
    {
        return 'social';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            __('Nouveau cercle'),
            __('Vous faites maintenant partie de « :circle » (ajouté par :name).', ['circle' => $this->householdName, 'name' => $this->actorFirstName]),
            "/households/{$this->householdId}",
            "household-added-{$this->householdId}",
        );
    }
}
