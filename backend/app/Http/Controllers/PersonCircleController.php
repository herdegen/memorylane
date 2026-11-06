<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Person;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Cercles d'une fiche : y ajouter une fiche qui existe déjà ailleurs (la
 * femme d'un ami dans la bande, un ami dans la famille, une fiche commune à
 * deux familles), ou l'en retirer.
 * Ajouter : quelqu'un qui VOIT la fiche (liaison {person}), membre du cercle
 * visé et qui peut y faire entrer des gens (Household::allowsInvitesFrom).
 * Retirer : l'admin, ou celui qui l'a ajoutée (cercle d'amis).
 */
class PersonCircleController extends Controller
{
    public function index(Request $request, Person $person)
    {
        return response()->json($this->state($request->user(), $person));
    }

    public function store(Request $request, Person $person)
    {
        $validated = $request->validate(['household_id' => ['required', 'uuid']]);
        $user = $request->user();

        $household = Household::findOrFail($validated['household_id']);
        abort_unless($this->canAddTo($user, $household, $person), 403, __('Vous ne pouvez pas ajouter cette fiche à ce cercle.'));

        $household->people()->syncWithoutDetaching([$person->id => ['added_by' => $user->id]]);
        // Une fiche entrée dans une famille fait partie du répertoire familial.
        if ($household->isFamily() && ! $person->in_family_directory) {
            $person->forceFill(['in_family_directory' => true])->save();
        }

        return response()->json($this->state($user, $person));
    }

    public function destroy(Request $request, Person $person, Household $household)
    {
        $user = $request->user();

        if ($household->isFriends()) {
            $pivot = $household->people()->whereKey($person->id)->first()?->pivot;
            abort_unless($pivot, 404);
            abort_unless($household->isManagedBy($user) || $pivot->added_by === $user->id, 403);
            $household->people()->detach($person->id);
        } else {
            abort_unless($user->isAdmin(), 403, __('Seul un administrateur retire une fiche de la famille.'));
            abort_unless($household->people()->whereKey($person->id)->exists(), 404);
            $household->people()->detach($person->id);
            // Plus dans aucune famille : la fiche quitte le répertoire familial.
            if (! $person->circles()->where('kind', Household::KIND_FAMILY)->exists()) {
                $person->forceFill(['in_family_directory' => false])->save();
            }
        }

        return response()->json($this->state($user, $person->fresh()));
    }

    private function canAddTo(User $user, Household $household, Person $person): bool
    {
        if (! $household->allowsInvitesFrom($user)) {
            return false;
        }

        // Seulement vers un cercle dont on est membre (l'admin : tous).
        if (! $user->isAdmin() && ! in_array($household->id, $user->householdIds(), true)) {
            return false;
        }

        return ! $household->people()->whereKey($person->id)->exists();
    }

    /**
     * Où est la fiche (parmi ce que le viewer connaît) et où peut-il l'ajouter.
     */
    private function state(User $user, Person $person): array
    {
        $mine = $user->isAdmin()
            ? Household::orderBy('name')->get()
            : Household::whereIn('id', $user->householdIds())->orderBy('name')->get();

        $pivots = $person->circles()->get()->keyBy('id');

        $in = [];
        $canAdd = [];
        foreach ($mine as $h) {
            if ($p = $pivots->get($h->id)) {
                $in[] = ['id' => $h->id, 'name' => $h->name, 'kind' => $h->kind, 'can_remove' => $h->isFamily()
                    ? $user->isAdmin()
                    : $h->isManagedBy($user) || $p->pivot->added_by === $user->id];
            } elseif ($this->canAddTo($user, $h, $person)) {
                $canAdd[] = ['id' => $h->id, 'name' => $h->name, 'kind' => $h->kind];
            }
        }

        return ['in' => $in, 'can_add' => $canAdd];
    }
}
