<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Prévient un membre retiré d'un cercle par son responsable. */
class RemovedFromCircle extends Notification
{
    use Queueable;

    public function __construct(
        public string $circleName,
        public string $byName,
        public bool $photosWithdrawn,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Vous ne faites plus partie de « {$this->circleName} »")
            ->greeting('Bonjour '.explode(' ', trim($notifiable->name))[0].',')
            ->line("{$this->byName} vous a retiré·e du cercle « {$this->circleName} » sur MemoryLane.")
            ->line($this->photosWithdrawn
                ? 'Les photos et albums que vous y aviez partagés en ont été retirés : ils restent à vous, en privé.'
                : 'Les photos et albums que vous y aviez partagés y restent visibles ; ils sont toujours à vous.')
            ->line('Votre compte et vos autres cercles ne changent pas. Une question ? Répondez simplement à ce mail.')
            ->action('Ouvrir MemoryLane', url('/dashboard'));
    }
}
