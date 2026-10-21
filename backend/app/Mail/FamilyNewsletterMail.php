<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Newsletter hebdomadaire de la famille (contenu composé par
 * FamilyNewsletterBuilder). Désabonnement en un clic : lien en pied de mail
 * et en-têtes List-Unsubscribe (Gmail, Apple Mail…).
 */
class FamilyNewsletterMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array{subject: string, preheader: string, sections: list<array>} $issue */
    public function __construct(public User $user, public array $issue) {}

    public static function unsubscribeUrl(User $user): string
    {
        return URL::signedRoute('newsletter.unsubscribe', ['user' => $user->id]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->issue['subject']);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.self::unsubscribeUrl($this->user).'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.newsletter-family',
            text: 'mail.newsletter-family-text',
            with: [
                'firstName' => strtok($this->user->name, ' '),
                'issue' => $this->issue,
                'by' => collect($this->issue['sections'])->keyBy('key'),
                'unsubscribeUrl' => self::unsubscribeUrl($this->user),
                'settingsUrl' => url('/profile'),
            ],
        );
    }
}
