<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** « Élodie a rejoint MemoryLane » — à la personne qui l'a invitée. */
class InvitationAccepted extends PushNotification
{
    public function __construct(
        public string $newcomerFirstName,
        public string $personId,
    ) {}

    public static function category(): string
    {
        return 'social';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return $this->message(
            __('Invitation acceptée'),
            __(':name a rejoint MemoryLane grâce à vous.', ['name' => $this->newcomerFirstName]),
            "/people/{$this->personId}",
            "invitation-accepted-{$this->personId}",
        );
    }
}
