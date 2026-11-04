<?php

namespace App\Mail;

use App\Mail\Concerns\NewsletterUnsubscribe;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Lettre du dimanche des cercles d'amis (contenu composé par
 * FriendsNewsletterBuilder). Même désabonnement que la lettre famille.
 */
class FriendsNewsletterMail extends Mailable implements ShouldQueue
{
    use NewsletterUnsubscribe, Queueable, SerializesModels;

    /** @param array{subject: string, preheader: string, circle: string, sections: list<array>} $issue */
    public function __construct(public User $user, public array $issue) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->issue['subject']);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.newsletter-friends',
            text: 'mail.newsletter-friends-text',
            with: [
                'firstName' => strtok($this->user->name, ' '),
                'issue' => $this->issue,
                'unsubscribeUrl' => self::unsubscribeUrl($this->user),
                'settingsUrl' => url('/profile'),
            ],
        );
    }
}
