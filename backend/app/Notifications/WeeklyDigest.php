<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WeeklyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param int $mediaCount Nombre de nouveaux médias cette semaine
     * @param array $uploaderNames Prénoms de ceux qui ont ajouté des médias
     * @param array $samples [['url' => vignette signée 7 jours, 'name' => nom], …] (max 4)
     */
    public function __construct(
        public int $mediaCount,
        public array $uploaderNames = [],
        public array $samples = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Cette semaine sur MemoryLane')
            ->greeting('Bonjour ' . $notifiable->name . ',');

        $mail->line(
            $this->mediaCount === 1
                ? 'Un nouveau souvenir a rejoint la famille cette semaine.'
                : "{$this->mediaCount} nouveaux souvenirs ont rejoint la famille cette semaine."
        );

        if (! empty($this->uploaderNames)) {
            $names = implode(', ', $this->uploaderNames);
            $mail->line("Ajoutés par : {$names}.");
        }

        foreach ($this->samples as $sample) {
            // Vignettes intégrées au corps du mail (URLs signées 7 jours)
            $mail->line("![{$sample['name']}]({$sample['url']})");
        }

        return $mail
            ->action('Voir les nouveautés', url('/media'))
            ->line('Bonne semaine, et à bientôt sur MemoryLane.')
            ->salutation('MemoryLane — les souvenirs de la famille');
    }
}
