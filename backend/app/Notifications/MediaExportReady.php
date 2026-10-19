<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Liens de téléchargement de « Télécharger mes photos » (valables 7 jours). */
class MediaExportReady extends Notification
{
    /** @param list<array{name: string, url: string, size: int}> $parts */
    public function __construct(public array $parts, public int $count) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Vos photos MemoryLane sont prêtes à télécharger')
            ->greeting('Bonjour '.explode(' ', trim($notifiable->name))[0].',')
            ->line("Vos {$this->count} photos et vidéos (originaux) sont prêtes, en ".count($this->parts).' archive'.(count($this->parts) > 1 ? 's' : '').' ZIP. Chaque archive contient un fichier index.csv (date, titre, albums, personnes).');

        foreach ($this->parts as $part) {
            $mail->line("[{$part['name']}]({$part['url']}) — ".number_format($part['size'] / 1048576, 0, ',', ' ').' Mo');
        }

        return $mail->line('Ces liens sont valables 7 jours. Il suffit de redemander un export depuis votre profil ensuite.');
    }
}
