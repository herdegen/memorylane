<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Services\GenealogyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Partager une branche avec une autre famille (dont on est membre) : la
 * fiche, ses descendants, ses ancêtres, ou les deux, deviennent des fiches
 * communes aux deux familles. Chaque famille n'y voit que ce qui est à elle
 * autour. Les personnes vivantes restent à l'écart sauf demande explicite
 * (« avec leur accord ») ; les photos ne suivent pas, sauf les siennes si on
 * le demande. Aperçu (GET) avant de partager (POST).
 */
class BranchShareController extends Controller
{
    public const SCOPES = ['person', 'descendants', 'ancestors', 'both'];

    public function preview(Request $request, Person $person)
    {
        $plan = $this->plan($request, $person);

        return response()->json([
            'family' => $plan['target']->name,
            'branch' => $plan['branch'],
            'living' => $plan['living'],
            'living_included' => $plan['livingIncluded'],
            'already' => count($plan['already']),
            'to_share' => count($plan['toShare']),
            'photos' => count($plan['photos']),
        ]);
    }

    public function store(Request $request, Person $person)
    {
        $plan = $this->plan($request, $person);
        $user = $request->user();

        DB::transaction(function () use ($plan, $user, $request) {
            $plan['target']->people()->syncWithoutDetaching(
                collect($plan['toShare'])->mapWithKeys(fn ($id) => [$id => ['added_by' => $user->id]])->all()
            );
            if ($request->boolean('photos')) {
                $plan['target']->media()->syncWithoutDetaching(
                    collect($plan['photos'])->mapWithKeys(fn ($id) => [$id => ['added_by' => $user->id]])->all()
                );
            }
        });

        return response()->json([
            'shared' => count($plan['toShare']),
            'photos' => $request->boolean('photos') ? count($plan['photos']) : 0,
            'family' => $plan['target']->name,
        ]);
    }

    /**
     * @return array{target: Household, branch: int, living: int, livingIncluded: bool, already: list<string>, toShare: list<string>, photos: list<string>}
     */
    private function plan(Request $request, Person $person): array
    {
        $validated = $request->validate([
            'household_id' => ['required', 'uuid'],
            'scope' => ['required', Rule::in(self::SCOPES)],
            'living' => ['nullable', 'boolean'],
            'photos' => ['nullable', 'boolean'],
        ]);
        $user = $request->user();

        // Vers une autre famille dont on est membre et où l'on peut faire entrer des gens.
        $target = Household::findOrFail($validated['household_id']);
        abort_unless($target->isFamily() && ($user->isAdmin() || $target->isMember($user)) && $target->allowsInvitesFrom($user), 403,
            __('Vous ne pouvez pas partager vers cette famille.'));

        // Seulement ce qu'il voit lui-même, dans le répertoire familial.
        $branch = Person::visibleTo($user)->where('people.in_family_directory', true)
            ->whereIn('people.id', app(GenealogyService::class)->branchOf($person->id, $validated['scope']))
            ->get(['people.id', 'people.birth_date', 'people.death_date', 'people.death_place']);

        // Vivant (ou peut-être) : ni décès connu, né il y a moins de 105 ans
        // ou date inconnue — à l'écart sans accord.
        $alive = fn (Person $p) => ! $p->death_date && blank($p->death_place)
            && (! $p->birth_date || $p->birth_date->gt(now()->subYears(105)));
        $livingIncluded = $request->boolean('living');
        $chosen = ($livingIncluded ? $branch : $branch->reject($alive))->pluck('id');

        $already = $target->people()->whereIn('people.id', $chosen)->pluck('people.id')->all();
        $toShare = $chosen->diff($already)->values()->all();

        // Ses photos où figurent ces personnes, pas encore dans cette famille.
        $photos = Media::where('user_id', $user->id)
            ->whereHas('people', fn ($q) => $q->whereIn('people.id', $chosen))
            ->whereDoesntHave('households', fn ($h) => $h->whereKey($target->id))
            ->pluck('id')->all();

        return [
            'target' => $target,
            'branch' => $branch->count(),
            'living' => $branch->filter($alive)->count(),
            'livingIncluded' => $livingIncluded,
            'already' => $already,
            'toShare' => $toShare,
            'photos' => $photos,
        ];
    }
}
