<?php

namespace App\Mail;

use App\Models\Invitation;
use App\Models\Media;
use App\Models\Person;
use App\Services\PersonPortraitService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Mail de première invitation : lettre de présentation du projet, fiche de
 * la personne dans l'arbre (avec sa photo), captures de l'application et
 * bouton « Créer mon accès ». Images embarquées (cid) : rien n'est public.
 */
class InvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Captures illustratives, dans storage/app/mail/invitation/ (photos de
     * famille : jamais dans le dépôt public). Absentes → simplement omises.
     */
    public const SCREENSHOTS = ['accueil', 'fiche', 'arbre', 'carte', 'albums'];

    public function __construct(
        public Invitation $invitation,
        public string $url,
    ) {}

    /**
     * L'invitation part-elle du Gmail personnel de l'admin ?
     */
    public static function sentFromAdminGmail(Invitation $invitation): bool
    {
        return (bool) $invitation->inviter?->isAdmin()
            && config('mail.invitations.admin_mailer')
            && config('mail.invitations.admin_from_address');
    }

    public static function screenshotPath(string $key): string
    {
        return storage_path("app/mail/invitation/{$key}.jpg");
    }

    public function envelope(): Envelope
    {
        $inviter = $this->invitation->inviter;
        $inviterFirst = self::firstName($inviter?->name ?? config('mail.invitations.admin_from_name'));
        $verb = $this->invitation->formal ? 'vous invite' : "t'invite";

        if (self::sentFromAdminGmail($this->invitation)) {
            return new Envelope(
                from: new Address(config('mail.invitations.admin_from_address'), config('mail.invitations.admin_from_name')),
                subject: "{$inviterFirst} {$verb} sur MemoryLane, notre album de famille",
            );
        }

        return new Envelope(
            from: new Address(config('mail.from.address'), "{$inviterFirst} via MemoryLane"),
            replyTo: $inviter ? [new Address($inviter->email, $inviter->name)] : [],
            subject: "{$inviterFirst} {$verb} sur MemoryLane, l'album de notre famille",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.invitation',
            text: 'mail.invitation-text',
            with: $this->viewData(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $invitation = $this->invitation;
        $person = $invitation->person;
        $inviter = $invitation->inviter;
        $formal = $invitation->formal;
        $authorName = config('mail.invitations.admin_from_name');

        $screenshots = collect(self::SCREENSHOTS)
            ->mapWithKeys(fn ($key) => [$key => self::screenshotPath($key)])
            ->filter(fn ($path) => is_file($path))
            ->all();

        return [
            'tv' => fn (string $tu, string $vous) => $formal ? $vous : $tu,
            'formal' => $formal,
            'url' => $this->url,
            'expiresAt' => $invitation->expires_at?->copy()->locale('fr')->translatedFormat('j F Y'),
            'firstName' => self::firstName($person->first_name ?: $person->name),
            'personName' => self::prettyName($person),
            'birthLine' => $this->birthLine($person),
            'parentsLine' => $this->parentsLine($person),
            'initial' => mb_strtoupper(mb_substr($person->first_name ?: $person->name, 0, 1)),
            'avatar' => app(PersonPortraitService::class)->jpeg($person),
            'byAuthor' => (bool) $inviter?->isAdmin(),
            'inviterFirstName' => self::firstName($inviter?->name ?? $authorName),
            'authorFirstName' => self::firstName($authorName),
            'authorEmail' => config('mail.invitations.admin_from_address'),
            'contactPhone' => config('mail.invitations.contact_phone'),
            'repliesToAuthor' => self::sentFromAdminGmail($invitation),
            'personalMessage' => $invitation->message,
            'peopleCount' => self::roundDown(Person::count()),
            'photoCount' => self::roundDown(Media::count()),
            'screenshots' => $screenshots,
            'appUrl' => config('app.url'),
            'appHost' => parse_url(config('app.url'), PHP_URL_HOST),
        ];
    }

    public static function firstName(string $fullName): string
    {
        return explode(' ', trim($fullName))[0] ?: $fullName;
    }

    /**
     * « Jean DUPONT » (convention GEDCOM) → « Jean Dupont » : une
     * lettre se lit mieux sans noms en capitales.
     */
    public static function prettyName(Person $person, bool $withMaiden = true): string
    {
        $soften = fn (?string $s) => $s !== null && $s === mb_strtoupper($s)
            ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE)
            : $s;

        $name = $person->first_name || $person->last_name
            ? trim($person->first_name.' '.$soften($person->last_name))
            : $person->name;

        return $name.($withMaiden && $person->maiden_name ? ' ('.$soften($person->maiden_name).')' : '');
    }

    /** 347 → 340 : « plus de 340 personnes ». */
    private static function roundDown(int $n): int
    {
        return $n >= 20 ? intdiv($n, 10) * 10 : $n;
    }

    private function birthLine(Person $person): ?string
    {
        if (! $person->birth_date && ! $person->birth_place) {
            return null;
        }

        $born = match ($person->gender) {
            'F' => 'Née',
            'M' => 'Né',
            default => 'Né·e',
        };
        $line = $born;
        if ($person->birth_date) {
            $line .= ' le '.$person->birth_date->copy()->locale('fr')->translatedFormat('j F Y');
        }
        if ($person->birth_place) {
            $line .= ' à '.trim(explode(',', $person->birth_place)[0]);
        }

        return $line;
    }

    private function parentsLine(Person $person): ?string
    {
        $parents = collect([$person->father, $person->mother])
            ->filter()
            ->map(fn (Person $p) => self::prettyName($p, withMaiden: false));

        if ($parents->isEmpty()) {
            return null;
        }

        $child = match ($person->gender) {
            'F' => 'la fille',
            'M' => 'le fils',
            default => "l'enfant",
        };
        $verb = $this->invitation->formal ? 'vous êtes' : 'tu es';

        return "Dans l'arbre, {$verb} {$child} de ".$parents->implode(' et de ').'.';
    }
}
