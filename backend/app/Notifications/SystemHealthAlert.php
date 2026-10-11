<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte de supervision envoyée aux administrateurs : problèmes détectés par
 * la vérification horaire (ou retour à la normale). Envoi immédiat (pas de
 * file d'attente : une file bloquée est justement un des cas surveillés).
 */
class SystemHealthAlert extends Notification
{
    /**
     * @param  list<array{label: string, detail: string}>  $problems  vide = retour à la normale
     */
    public function __construct(public array $problems) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $app = config('app.name');

        if ($this->problems === []) {
            return (new MailMessage)
                ->subject("✅ {$app} : tout est rentré dans l'ordre")
                ->line("Les problèmes signalés précédemment sont résolus : toutes les vérifications sont au vert.")
                ->action('Voir l\'état du système', url('/admin'));
        }

        $n = count($this->problems);
        $mail = (new MailMessage)
            ->subject("⚠️ {$app} : {$n} point".($n > 1 ? 's' : '').' à vérifier')
            ->line('La vérification automatique a détecté :');

        foreach ($this->problems as $problem) {
            $mail->line("• **{$problem['label']}** — {$problem['detail']}");
        }

        return $mail
            ->action('Voir l\'état du système', url('/admin'))
            ->line('Tant que le problème persiste, ce mail est renvoyé au plus toutes les 12 heures.');
    }
}
