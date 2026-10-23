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
            ->subject(__('Votre compte MemoryLane a été fermé'))
            ->greeting(__('Bonjour :name,', ['name' => explode(' ', trim($notifiable->name))[0]]))
            ->line(__(':name, administrateur de MemoryLane, a fermé votre compte.', ['name' => $this->byName]))
            ->line(__('Votre fiche reste dans l\'arbre de la famille ; ce que vous aviez partagé a été confié à un administrateur, le reste a été retiré.'))
            ->line(__('Si vous pensez que c\'est une erreur, répondez simplement à ce mail.'));
    }
}
