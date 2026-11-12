<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\RecapService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Récaps : la page qui les rassemble, le contenu d'un récap, « déjà vu ». */
class RecapController extends Controller
{
    public function __construct(private RecapService $recaps) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Recaps/Index', [
            'featured' => $this->recaps->featured($user),
            'years' => $this->recaps->yearCards($user),
            'people' => $this->recaps->throughYearsPeople($user)
                ->map(fn ($row) => $this->recaps->card($row, $user))->values(),
        ]);
    }

    /** « Prénom au fil des ans » : diapositives et médias (JSON). */
    public function years(Request $request, Person $person)
    {
        $user = $request->user();
        abort_unless(Person::inContext($user)->whereKey($person->id)->exists(), 404);

        $recap = $this->recaps->throughYears($user, $person);
        abort_if($recap === null, 404);

        return response()->json($recap);
    }

    /** « L'année 2026 » : diapositives et médias (JSON). */
    public function year(Request $request, int $year)
    {
        abort_unless($year >= 2000 && $year <= now()->year, 404);
        $recap = $this->recaps->year($request->user(), $year);
        abort_if($recap === null, 404);

        return response()->json($recap);
    }

    public function seen(Request $request)
    {
        $validated = $request->validate(['key' => ['required', 'string', 'max:100', 'regex:/^[a-z]+:[0-9a-f-]{36}(:\d{4})?$|^[a-z]+:\d{4}(:[0-9a-f-]{36})?$/']]);
        $this->recaps->markSeen($request->user(), $validated['key']);

        return response()->json(['ok' => true]);
    }
}
