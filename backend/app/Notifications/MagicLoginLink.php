<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class MagicLoginLink extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Durée de validité du lien, en minutes.
     */
    public const EXPIRATION_MINUTES = 30;

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'login.magic.verify',
            now()->addMinutes(self::EXPIRATION_MINUTES),
            ['user' => $notifiable->id]
        );

        return (new MailMessage)
            ->subject('Votre lien de connexion MemoryLane')
            ->greeting('Bonjour ' . $notifiable->name . ',')
            ->line('Cliquez sur le bouton ci-dessous pour ouvrir MemoryLane. Pas besoin de mot de passe.')
            ->action('Ouvrir MemoryLane', $url)
            ->line('Ce lien est valable ' . self::EXPIRATION_MINUTES . ' minutes. Si vous n\'avez pas demandé ce lien, vous pouvez ignorer cet e-mail.')
            ->salutation('À bientôt sur MemoryLane');
    }
}
