<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Notifications\Push\PushNotification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

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

    /**
     * Mail pour tous ; push en plus pour ceux qui ont activé « Résumé de la
     * semaine » sur un appareil. La commande ne notifie que s'il y a du
     * nouveau : jamais de push vide.
     */
    public function via(object $notifiable): array
    {
        $channels = ['mail'];
        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush('digest')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $body = $this->mediaCount === 1 ? '1 nouveau souvenir' : "{$this->mediaCount} nouveaux souvenirs";
        if ($this->uploaderNames !== []) {
            $body .= ' (' . implode(', ', $this->uploaderNames) . ')';
        }

        // 48 h de durée de vie : passé lundi soir, le résumé n'a plus d'intérêt.
        return (new WebPushMessage)
            ->title('Cette semaine sur MemoryLane')
            ->body($body)
            ->icon(PushNotification::ICON)
            ->badge(PushNotification::ICON)
            ->lang('fr')
            ->tag('weekly-digest')
            ->data(['url' => '/media'])
            ->options(['TTL' => 172800, 'urgency' => 'normal']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Cette semaine sur MemoryLane')
            ->greeting('Bonjour ' . $notifiable->name . ',');

        $mail->line(
            $this->mediaCount === 1
                ? 'Un nouveau souvenir a été partagé avec vous cette semaine.'
                : "{$this->mediaCount} nouveaux souvenirs ont été partagés avec vous cette semaine."
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
            ->salutation('MemoryLane — les souvenirs de vos proches');
    }
}
