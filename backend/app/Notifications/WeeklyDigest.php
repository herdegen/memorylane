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
        // Membres de la famille : la lettre du dimanche remplace l'e-mail, ce
        // résumé ne part plus qu'en push.
        public bool $mail = true,
    ) {}

    /**
     * Mail pour tous ; push en plus pour ceux qui ont activé « Résumé de la
     * semaine » sur un appareil. La commande ne notifie que s'il y a du
     * nouveau : jamais de push vide.
     */
    public function via(object $notifiable): array
    {
        $channels = $this->mail ? ['mail'] : [];
        if (method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush('digest')) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $body = $this->uploaderNames !== []
            ? trans_choice(':count nouveau souvenir (:names)|:count nouveaux souvenirs (:names)', $this->mediaCount, ['names' => implode(', ', $this->uploaderNames)])
            : trans_choice(':count nouveau souvenir|:count nouveaux souvenirs', $this->mediaCount);

        // 48 h de durée de vie : passé lundi soir, le résumé n'a plus d'intérêt.
        return (new WebPushMessage)
            ->title(__('Cette semaine sur MemoryLane'))
            ->body($body)
            ->icon(PushNotification::ICON)
            ->badge(PushNotification::ICON)
            ->lang(app()->getLocale())
            ->tag('weekly-digest')
            ->data(['url' => '/media'])
            ->options(['TTL' => 172800, 'urgency' => 'normal']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('Cette semaine sur MemoryLane'))
            ->greeting(__('Bonjour :name,', ['name' => $notifiable->name]));

        $mail->line(trans_choice(
            'Un nouveau souvenir a été partagé avec vous cette semaine.|:count nouveaux souvenirs ont été partagés avec vous cette semaine.',
            $this->mediaCount
        ));

        if (! empty($this->uploaderNames)) {
            $names = implode(', ', $this->uploaderNames);
            $mail->line(__('Ajoutés par : :names.', ['names' => $names]));
        }

        foreach ($this->samples as $sample) {
            // Vignettes intégrées au corps du mail (URLs signées 7 jours)
            $mail->line("![{$sample['name']}]({$sample['url']})");
        }

        return $mail
            ->action(__('Voir les nouveautés'), url('/media'))
            ->line(__('Bonne semaine, et à bientôt sur MemoryLane.'))
            ->salutation(__('MemoryLane — les souvenirs de vos proches'));
    }
}
