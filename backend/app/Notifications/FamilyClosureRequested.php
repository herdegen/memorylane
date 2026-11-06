<?php

namespace App\Notifications;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Prévient les membres d'une famille qu'un responsable a demandé sa fermeture. */
class FamilyClosureRequested extends Notification
{
    use Queueable;

    public function __construct(
        public string $familyName,
        public string $byName,
        public ?CarbonInterface $closesOn,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $date = $this->closesOn?->locale(app()->getLocale())->isoFormat('LL');

        return (new MailMessage)
            ->subject(__('« :family » va fermer sur MemoryLane', ['family' => $this->familyName]))
            ->greeting(__('Bonjour :name,', ['name' => explode(' ', trim($notifiable->name))[0]]))
            ->line(__(':name a demandé la fermeture de la famille « :family », prévue le :date.', ['name' => $this->byName, 'family' => $this->familyName, 'date' => $date]))
            ->line(__('Vous pouvez la reprendre d’ici là : vous en deviendrez responsable et elle continuera. Sinon, vos photos et votre compte restent à vous, et vous pouvez les exporter.'))
            ->action(__('Voir la famille'), url('/households'));
    }
}
