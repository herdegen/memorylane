<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/** « Marie a ajouté une vidéo à ta fiche » — à valider par la personne. */
class FicheMediaPending extends PushNotification
{
    public function __construct(
        public string $actorFirstName,
        public string $mediaType,
        public string $mediaId,
    ) {}

    public static function category(): string
    {
        return 'social';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $body = $this->mediaType === 'video'
            ? __(':name a ajouté une vidéo à ta fiche. L\'afficher pour tout le monde ?', ['name' => $this->actorFirstName])
            : __(':name a ajouté une photo à ta fiche. L\'afficher pour tout le monde ?', ['name' => $this->actorFirstName]);

        return $this->message(
            __('À valider'),
            $body,
            "/media/{$this->mediaId}",
            "fiche-{$this->mediaId}",
        );
    }
}
