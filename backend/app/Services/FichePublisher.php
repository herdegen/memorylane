<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Push\FicheMediaPending;
use Illuminate\Support\Collection;

/**
 * « Ajouter à la fiche » : un média publié sur la fiche d'une personne est
 * visible de tous ceux qui voient cette fiche (cf. Media::accessibleBy).
 * Personne sans compte : publié tout de suite. Personne avec un compte :
 * en attente de SA validation (push + encart sur son accueil) ; en attendant
 * seuls l'auteur et elle le voient. Refusé ou retiré : reste identifié,
 * mais privé.
 */
class FichePublisher
{
    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const PRIVATE = 'private';

    public function __construct(private PushNotifier $push) {}

    /** Compte rattaché à la fiche (vrai compte, pas invité ni tablette). */
    public function accountOf(Person $person): ?User
    {
        return User::realPeople()->where('person_id', $person->id)->first();
    }

    /** @return string le statut obtenu (published ou pending) */
    public function publish(Media $media, Person $person, User $by): string
    {
        $account = $this->accountOf($person);
        $status = (! $account || $account->id === $by->id) ? self::PUBLISHED : self::PENDING;

        $media->people()->syncWithoutDetaching([$person->id => [
            'fiche_status' => $status,
            'fiche_added_by' => $by->id,
            'fiche_decided_at' => $status === self::PUBLISHED ? now() : null,
        ]]);

        if ($status === self::PENDING) {
            $this->push->social($account, new FicheMediaPending(
                strtok($by->name, ' ') ?: $by->name,
                $media->type,
                $media->id,
            ), $by);
        }

        return $status;
    }

    /** La personne concernée valide (publie) ou garde privé. */
    public function decide(Media $media, User $user, bool $publish): void
    {
        abort_unless($user->person_id && $this->statusFor($media, $user->person_id) === self::PENDING, 404);

        $media->people()->updateExistingPivot($user->person_id, [
            'fiche_status' => $publish ? self::PUBLISHED : self::PRIVATE,
            'fiche_decided_at' => now(),
        ]);
    }

    /** Retire de la fiche publique (auteur du média, admin ou la personne). */
    public function withdraw(Media $media, Person $person): void
    {
        $media->people()->updateExistingPivot($person->id, [
            'fiche_status' => self::PRIVATE,
            'fiche_decided_at' => now(),
        ]);
    }

    public function statusFor(Media $media, string $personId): ?string
    {
        return $media->people()->whereKey($personId)->first()?->pivot->fiche_status;
    }

    /**
     * Médias en attente de la validation de l'utilisateur (sa fiche).
     *
     * @return Collection<int, Media>
     */
    public function pendingFor(User $user): Collection
    {
        if (! $user->person_id) {
            return collect();
        }

        return Media::query()
            ->whereHas('people', fn ($p) => $p->whereKey($user->person_id)->where('media_person.fiche_status', self::PENDING))
            ->with(['conversions', 'user:id,name'])
            ->latest('media.created_at')
            ->get();
    }
}
