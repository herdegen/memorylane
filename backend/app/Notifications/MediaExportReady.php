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
            ->subject(__('Vos photos MemoryLane sont prêtes à télécharger'))
            ->greeting(__('Bonjour :name,', ['name' => explode(' ', trim($notifiable->name))[0]]))
            ->line(trans_choice('Vos :total photos et vidéos (originaux) sont prêtes, en :count archive ZIP. Chaque archive contient un fichier index.csv (date, titre, albums, personnes).|Vos :total photos et vidéos (originaux) sont prêtes, en :count archives ZIP. Chaque archive contient un fichier index.csv (date, titre, albums, personnes).', count($this->parts), ['total' => $this->count]));

        // Séparateur des milliers selon la langue (« 1 024 Mo » / « 1,024 MB »).
        $thousands = ['fr' => ' ', 'es' => '.'][app()->getLocale()] ?? ',';
        foreach ($this->parts as $part) {
            $size = __(':size Mo', ['size' => number_format($part['size'] / 1048576, 0, ',', $thousands)]);
            $mail->line("[{$part['name']}]({$part['url']}) — {$size}");
        }

        return $mail->line(__('Ces liens sont valables 7 jours. Il suffit de redemander un export depuis votre profil ensuite.'));
    }
}
