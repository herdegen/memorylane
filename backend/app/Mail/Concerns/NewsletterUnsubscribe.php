<?php

namespace App\Mail\Concerns;

use App\Models\User;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

/**
 * Désabonnement des lettres du dimanche (famille comme amis : une seule
 * préférence, User::wantsNewsletter) : lien signé en pied de mail et
 * en-têtes List-Unsubscribe pour le « un clic » des messageries.
 */
trait NewsletterUnsubscribe
{
    public static function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('newsletter.unsubscribe', ['user' => $user->id]);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.self::unsubscribeUrl($this->user).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
