<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Prévient la personne dont le compte est retiré par un administrateur.
 * Envoyé tout de suite (notifyNow) : le compte n'existera plus ensuite.
 */
class AccountRemovedByAdmin extends Notification
{
    public function __construct(public string $byName) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre compte MemoryLane a été fermé')
            ->greeting('Bonjour '.explode(' ', trim($notifiable->name))[0].',')
            ->line("{$this->byName}, administrateur de MemoryLane, a fermé votre compte.")
            ->line('Votre fiche reste dans l\'arbre de la famille ; ce que vous aviez partagé a été confié à un administrateur, le reste a été retiré.')
            ->line('Si vous pensez que c\'est une erreur, répondez simplement à ce mail.');
    }
}
