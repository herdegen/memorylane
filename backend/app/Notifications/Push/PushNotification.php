<?php

namespace App\Notifications\Push;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Base des notifications push. Chaque sous-classe appartient à une catégorie
 * (clé de users.preferences.push) : la notification n'est envoyée que si
 * l'utilisateur a activé cette catégorie ET possède un appareil abonné.
 *
 * Règle de la maison : les constructeurs ne reçoivent que des scalaires/
 * tableaux (pas de modèles) — un foyer ou un album supprimé entre la mise en
 * file et l'envoi ne doit pas faire échouer le job.
 */
abstract class PushNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const ICON = '/icons/icon-192.png';

    /** Catégorie de préférence (cf. User::PUSH_CATEGORIES). */
    abstract public static function category(): string;

    abstract public function toWebPush(object $notifiable, Notification $notification): WebPushMessage;

    public function via(object $notifiable): array
    {
        return method_exists($notifiable, 'wantsPush') && $notifiable->wantsPush(static::category())
            ? [WebPushChannel::class]
            : [];
    }

    /**
     * Message standard : icône de l'app, lien profond, étiquette de
     * dédoublonnage côté système et durée de vie (au-delà, un téléphone
     * éteint ne recevra plus le message — inutile d'annoncer hier).
     */
    protected function message(string $title, string $body, string $url, string $tag, int $ttlSeconds = 86400): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($title)
            ->body($body)
            ->icon(self::ICON)
            ->badge(self::ICON)
            ->lang('fr')
            ->tag($tag)
            ->data(['url' => $url])
            ->options(['TTL' => $ttlSeconds, 'urgency' => 'normal']);
    }

    /** Prénom affiché dans les messages (« Marie a partagé… »). */
    public static function firstName(string $fullName): string
    {
        return explode(' ', trim($fullName))[0] ?: $fullName;
    }
}
