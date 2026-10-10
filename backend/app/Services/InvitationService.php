<?php

namespace App\Services;

use App\Mail\InvitationMail;
use App\Models\Household;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Push\InvitationAccepted;
use App\Notifications\Push\PushNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invitations à créer son accès : par l'admin (back-office, envoyées depuis
 * son Gmail) ou par un membre depuis la fiche d'un proche. Le lien mène à
 * /bienvenue/{jeton} où la personne choisit son mot de passe ; son compte
 * est alors relié à sa fiche et ajouté au foyer d'accueil.
 */
class InvitationService
{
    /** Au-delà, une fiche sans date de décès est un ancêtre, pas un vivant. */
    private const MAX_AGE_YEARS = 110;

    public function __construct(private PushNotifier $push) {}

    /**
     * Raison pour laquelle cette fiche ne peut pas recevoir d'invitation
     * (null si elle le peut).
     */
    public function personIneligibility(Person $person): ?string
    {
        if ($person->death_date !== null) {
            return 'Cette personne est décédée.';
        }

        if ($person->birth_date !== null && $person->birth_date->lt(now()->subYears(self::MAX_AGE_YEARS))) {
            return 'Cette personne est née il y a plus de '.self::MAX_AGE_YEARS.' ans.';
        }

        if ($person->birth_date === null && $this->looksLikeAncestor($person)) {
            return 'Cette personne semble appartenir à une génération ancienne de l\'arbre.';
        }

        if (User::where('person_id', $person->id)->exists()) {
            return 'Cette personne a déjà un compte.';
        }

        if (Invitation::open()->where('person_id', $person->id)->exists()) {
            return 'Une invitation est déjà en cours pour cette personne.';
        }

        return null;
    }

    /**
     * Fiche sans date de naissance (fréquent dans un GEDCOM) : on la devine
     * ancienne si un parent, un conjoint ou un enfant l'est.
     */
    private function looksLikeAncestor(Person $person): bool
    {
        $born = fn (?Person $p, int $years) => $p?->birth_date !== null && $p->birth_date->lt(now()->subYears($years));

        if ($born($person->father, 130) || $born($person->mother, 130)) {
            return true;
        }

        foreach ($person->spouses as $spouse) {
            if ($born($spouse, self::MAX_AGE_YEARS)) {
                return true;
            }
        }

        foreach ($person->children as $child) {
            if ($born($child, 90)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Crée l'invitation puis l'envoie. Lève une ValidationException
     * (clés email / person_id) si elle n'est pas possible. Si l'envoi
     * échoue : l'invitation est gardée (le back-office peut la renvoyer)
     * ou supprimée ($keepOnFailure = false, pour qu'un membre réessaie).
     *
     * @param  array{formal?: bool, message?: ?string, household_id?: ?string}  $options
     */
    public function invite(User $inviter, string $email, Person $person, array $options = [], bool $keepOnFailure = true): Invitation
    {
        $invitation = $this->create($inviter, $email, $person, $options);

        try {
            $this->send($invitation);
        } catch (\Throwable $e) {
            if (! $keepOnFailure) {
                $invitation->delete();
            }
            throw $e;
        }

        return $invitation;
    }

    /**
     * Crée l'invitation sans l'envoyer (contrôles d'éligibilité compris).
     *
     * @param  array{formal?: bool, message?: ?string, household_id?: ?string}  $options
     */
    public function create(User $inviter, string $email, Person $person, array $options = []): Invitation
    {
        $email = Str::lower(trim($email));

        if ($reason = $this->personIneligibility($person)) {
            throw ValidationException::withMessages(['person_id' => $reason]);
        }

        if (User::whereRaw('lower(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages(['email' => 'Un compte existe déjà avec cette adresse.']);
        }

        if (Invitation::open()->where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Une invitation est déjà en cours pour cette adresse.']);
        }

        // Seul un admin choisit le foyer. Un membre fait entrer l'invité dans
        // le foyer d'accueil s'il en fait partie, sinon dans son cercle
        // courant (un ami n'invite pas dans le cercle familial).
        if ($inviter->isAdmin() && array_key_exists('household_id', $options)) {
            $householdId = $options['household_id'];
        } else {
            $welcome = Household::welcome();
            $householdId = $welcome && ($inviter->isAdmin() || $welcome->isMember($inviter))
                ? $welcome->id
                : $inviter->currentCircle()?->id;
        }

        return Invitation::create([
            'email' => $email,
            'person_id' => $person->id,
            'household_id' => $householdId,
            'invited_by' => $inviter->id,
            'formal' => (bool) ($options['formal'] ?? false),
            'message' => filled($options['message'] ?? null) ? trim($options['message']) : null,
        ]);
    }

    /**
     * (Ré)envoie l'invitation avec un jeton neuf (l'ancien lien cesse de
     * fonctionner) et repousse l'échéance. En cas d'échec SMTP, l'erreur est
     * gardée sur l'invitation puis relancée.
     */
    public function send(Invitation $invitation): void
    {
        abort_if($invitation->accepted_at !== null, 422, 'Cette invitation a déjà été acceptée.');

        $token = Str::random(48);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'token' => $token,
            'revoked_at' => null,
            'expires_at' => now()->addDays(Invitation::VALID_DAYS),
        ])->save();

        try {
            $this->mailerFor($invitation)
                ->to($invitation->email)
                ->send(new InvitationMail($invitation->fresh(), $invitation->url()));
        } catch (\Throwable $e) {
            $invitation->forceFill(['last_error' => Str::limit($e->getMessage(), 1000)])->save();
            throw $e;
        }

        $invitation->forceFill(['sent_at' => now(), 'last_error' => null])->save();
    }

    /**
     * Envoie à l'admin lui-même un exemplaire du mail (lien factice), pour
     * vérifier le rendu dans sa boîte.
     */
    public function sendPreviewTo(Invitation $invitation, string $email): void
    {
        $this->mailerFor($invitation)
            ->to($email)
            ->send(new InvitationMail($invitation, route('login')));
    }

    public function revoke(Invitation $invitation): void
    {
        $invitation->forceFill([
            'revoked_at' => now(),
            'token_hash' => null,
            'token' => null,
        ])->save();
    }

    /**
     * Crée le compte, le relie à la fiche, l'ajoute au foyer et clôt
     * l'invitation (lien à usage unique).
     *
     * @param  array{name: string, password: string}  $data
     */
    public function accept(Invitation $invitation, array $data): User
    {
        $user = DB::transaction(function () use ($invitation, $data) {
            $invitation = Invitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            abort_unless($invitation->isUsable(), 410, 'Cette invitation n\'est plus valable.');

            if (User::whereRaw('lower(email) = ?', [Str::lower($invitation->email)])->exists()) {
                throw ValidationException::withMessages(['email' => 'Un compte existe déjà avec cette adresse : connectez-vous plutôt.']);
            }

            $personTaken = User::where('person_id', $invitation->person_id)->exists();

            $user = User::create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => $data['password'],
                'role' => 'user',
                'person_id' => $personTaken ? null : $invitation->person_id,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            if ($invitation->household_id) {
                $user->households()->syncWithoutDetaching([
                    $invitation->household_id => ['invited_by' => $invitation->invited_by],
                ]);
            }

            $invitation->forceFill([
                'accepted_at' => now(),
                'user_id' => $user->id,
                'token_hash' => null,
                'token' => null,
            ])->save();

            return $user;
        });

        if ($inviter = $invitation->inviter) {
            $this->push->social(
                $inviter,
                new InvitationAccepted(PushNotification::firstName($user->name), $invitation->person_id),
                $user,
            );
        }

        return $user;
    }

    /**
     * Invitations de l'admin : depuis son Gmail quand il est configuré
     * (le destinataire reconnaît l'expéditeur). Sinon, mailer par défaut.
     */
    private function mailerFor(Invitation $invitation): \Illuminate\Contracts\Mail\Mailer
    {
        $mailer = InvitationMail::sentFromAdminGmail($invitation)
            ? config('mail.invitations.admin_mailer')
            : null;

        return Mail::mailer($mailer);
    }
}
