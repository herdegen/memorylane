<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

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

    /**
     * URL signée à usage unique (jeton gardé en cache le temps de validité).
     */
    public static function urlFor(User $user): string
    {
        $nonce = Str::random(40);
        Cache::put("magic-link:{$nonce}", $user->id, now()->addMinutes(self::EXPIRATION_MINUTES));

        return URL::temporarySignedRoute(
            'login.magic.verify',
            now()->addMinutes(self::EXPIRATION_MINUTES),
            ['user' => $user->id, 'n' => $nonce]
        );
    }

    /** Consomme le jeton : vrai une seule fois, pour le bon compte. */
    public static function consume(User $user, string $nonce): bool
    {
        return $nonce !== '' && Cache::pull("magic-link:{$nonce}") === $user->id;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = self::urlFor($notifiable);

        return (new MailMessage)
            ->subject(__('Votre lien de connexion MemoryLane'))
            ->greeting(__('Bonjour :name,', ['name' => $notifiable->name]))
            ->line(__('Cliquez sur le bouton ci-dessous pour ouvrir MemoryLane. Pas besoin de mot de passe.'))
            ->action(__('Ouvrir MemoryLane'), $url)
            ->line(__('Ce lien est valable :minutes minutes. Si vous n\'avez pas demandé ce lien, vous pouvez ignorer cet e-mail.', ['minutes' => self::EXPIRATION_MINUTES]))
            ->salutation(__('À bientôt sur MemoryLane'));
    }
}
