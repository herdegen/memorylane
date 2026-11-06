<?php

namespace App\Services;

use App\Models\DetectedFace;
use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use App\Notifications\FamilyClosureRequested;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Fermer une famille : un responsable le demande, les membres sont prévenus
 * et ont 30 jours pour la reprendre (l'un d'eux devient responsable, la
 * fermeture est annulée). À l'échéance, le cercle disparaît : ses comptes
 * restent (avec leurs photos et leurs autres cercles), les fiches communes
 * restent dans les autres familles, et une fiche qui n'est plus dans aucune
 * famille est archivée — gardée pour la généalogie, invisible, sans photos —
 * sauf la fiche d'un compte, qui reste à la personne.
 */
class FamilyClosure
{
    public const DAYS = 30;

    public function request(Household $family, User $by): void
    {
        $family->forceFill(['closure_requested_at' => now(), 'closure_requested_by' => $by->id])->save();

        foreach ($family->members()->where('users.id', '!=', $by->id)->whereNull('memorial_at')->get() as $member) {
            $member->notify(new FamilyClosureRequested($family->name, $by->name, $this->closesOn($family)));
        }
    }

    public function cancel(Household $family): void
    {
        $family->forceFill(['closure_requested_at' => null, 'closure_requested_by' => null])->save();
    }

    /** Un membre reprend la famille : il en devient responsable, la fermeture est annulée. */
    public function takeOver(Household $family, User $member): void
    {
        $family->setRole($member, Household::ROLE_MANAGER);
        $this->cancel($family);
    }

    public function closesOn(Household $family): ?CarbonInterface
    {
        return $family->closure_requested_at?->copy()->addDays(self::DAYS);
    }

    /** Ferme la famille maintenant (échéance atteinte, ou administrateur). */
    public function close(Household $family): void
    {
        DB::transaction(function () use ($family) {
            $ids = $family->people()->pluck('people.id');
            $family->delete();

            $accountPeople = User::whereNotNull('person_id')->pluck('person_id')->flip();
            $orphans = Person::whereIn('id', $ids)
                ->where('in_family_directory', true)
                ->whereDoesntHave('circles', fn ($c) => $c->where('kind', Household::KIND_FAMILY))
                ->get();
            foreach ($orphans as $person) {
                if (isset($accountPeople[$person->id])) {
                    continue; // la fiche d'un compte reste à la personne
                }
                $person->media()->detach();
                DetectedFace::where('person_id', $person->id)->update(['person_id' => null, 'status' => 'unmatched']);
                $person->forceFill(['archived_at' => now(), 'avatar_media_id' => null])->saveQuietly();
            }
        });
    }

    /** Familles dont le délai est écoulé. */
    public function due()
    {
        return Household::where('kind', Household::KIND_FAMILY)
            ->whereNotNull('closure_requested_at')
            ->where('closure_requested_at', '<=', now()->subDays(self::DAYS));
    }
}
