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
        $formal = $this->invitation->formal;
        // Invitation dans un cercle d'amis : on parle du cercle, pas de la famille.
        $circle = $this->friendsCircle();
        $circleSubject = $circle
            ? ($formal
                ? __(':name vous invite dans « :circle » sur MemoryLane', ['name' => $inviterFirst, 'circle' => $circle->name])
                : __(':name t\'invite dans « :circle » sur MemoryLane', ['name' => $inviterFirst, 'circle' => $circle->name]))
            : null;

        if (self::sentFromAdminGmail($this->invitation)) {
            return new Envelope(
                from: new Address(config('mail.invitations.admin_from_address'), config('mail.invitations.admin_from_name')),
                subject: $circleSubject ?? ($formal
                    ? __(':name vous invite sur MemoryLane, notre album de famille', ['name' => $inviterFirst])
                    : __(':name t\'invite sur MemoryLane, notre album de famille', ['name' => $inviterFirst])),
            );
        }

        return new Envelope(
            from: new Address(config('mail.from.address'), "{$inviterFirst} via MemoryLane"),
            replyTo: $inviter ? [new Address($inviter->email, $inviter->name)] : [],
            subject: $circleSubject ?? ($formal
                ? __(':name vous invite sur MemoryLane, l\'album de notre famille', ['name' => $inviterFirst])
                : __(':name t\'invite sur MemoryLane, l\'album de notre famille', ['name' => $inviterFirst])),
        );
    }

    public function content(): Content
    {
        $friends = $this->friendsCircle() !== null;

        return new Content(
            view: $friends ? 'mail.invitation-friends' : 'mail.invitation',
            text: $friends ? 'mail.invitation-friends-text' : 'mail.invitation-text',
            with: $this->viewData(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    /** Cercle d'amis visé par l'invitation, ou null (invitation famille). */
    private function friendsCircle(): ?\App\Models\Household
    {
        $circle = $this->invitation->household;

        return $circle?->isFriends() ? $circle : null;
    }

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
            'expiresAt' => $invitation->expires_at?->copy()->locale(app()->getLocale())->translatedFormat('j F Y'),
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
            // Numéro de téléphone : seulement dans le mail famille, pas entre amis.
            'contactPhone' => $this->friendsCircle() ? null : config('mail.invitations.contact_phone'),
            'repliesToAuthor' => self::sentFromAdminGmail($invitation),
            'personalMessage' => $invitation->message,
            'peopleCount' => self::roundDown(Person::count()),
            'photoCount' => self::roundDown(Media::count()),
            'screenshots' => $screenshots,
            'appUrl' => config('app.url'),
            'appHost' => parse_url(config('app.url'), PHP_URL_HOST),
            // Variante « amis » (cercle d'amis de l'invitation).
            'circleName' => $this->friendsCircle()?->name,
            'circlePeopleCount' => $this->friendsCircle()?->people()->count() ?? 0,
            'circlePhotoCount' => $this->friendsCircle()?->photoCount() ?? 0,
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

        $date = $person->birth_date?->copy()->locale(app()->getLocale())->translatedFormat('j F Y');
        $place = $person->birth_place ? trim(explode(',', $person->birth_place)[0]) : null;
        $values = array_filter(['date' => $date, 'place' => $place], fn ($v) => $v !== null);

        // Une phrase complète par genre et par combinaison date / lieu.
        if ($date !== null && $place !== null) {
            return match ($person->gender) {
                'F' => __('Née le :date à :place', $values),
                'M' => __('Né le :date à :place', $values),
                default => __('Né·e le :date à :place', $values),
            };
        }
        if ($date !== null) {
            return match ($person->gender) {
                'F' => __('Née le :date', $values),
                'M' => __('Né le :date', $values),
                default => __('Né·e le :date', $values),
            };
        }

        return match ($person->gender) {
            'F' => __('Née à :place', $values),
            'M' => __('Né à :place', $values),
            default => __('Né·e à :place', $values),
        };
    }

    private function parentsLine(Person $person): ?string
    {
        $parents = collect([$person->father, $person->mother])
            ->filter()
            ->map(fn (Person $p) => self::prettyName($p, withMaiden: false));

        if ($parents->isEmpty()) {
            return null;
        }

        $values = ['parents' => $parents->count() > 1
            ? __(':first et de :second', ['first' => $parents->first(), 'second' => $parents->last()])
            : $parents->first()];

        if ($this->invitation->formal) {
            return match ($person->gender) {
                'F' => __('Dans l\'arbre, vous êtes la fille de :parents.', $values),
                'M' => __('Dans l\'arbre, vous êtes le fils de :parents.', $values),
                default => __('Dans l\'arbre, vous êtes l\'enfant de :parents.', $values),
            };
        }

        return match ($person->gender) {
            'F' => __('Dans l\'arbre, tu es la fille de :parents.', $values),
            'M' => __('Dans l\'arbre, tu es le fils de :parents.', $values),
            default => __('Dans l\'arbre, tu es l\'enfant de :parents.', $values),
        };
    }
}
