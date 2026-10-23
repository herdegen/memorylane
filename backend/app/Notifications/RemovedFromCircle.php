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
            ->subject(__('Vous ne faites plus partie de « :circle »', ['circle' => $this->circleName]))
            ->greeting(__('Bonjour :name,', ['name' => explode(' ', trim($notifiable->name))[0]]))
            ->line(__(':name vous a retiré·e du cercle « :circle » sur MemoryLane.', ['name' => $this->byName, 'circle' => $this->circleName]))
            ->line($this->photosWithdrawn
                ? __('Les photos et albums que vous y aviez partagés en ont été retirés : ils restent à vous, en privé.')
                : __('Les photos et albums que vous y aviez partagés y restent visibles ; ils sont toujours à vous.'))
            ->line(__('Votre compte et vos autres cercles ne changent pas. Une question ? Répondez simplement à ce mail.'))
            ->action(__('Ouvrir MemoryLane'), url('/dashboard'));
    }
}
