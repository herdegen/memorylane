<?php

namespace App\Http\Controllers;

use App\Services\DepartureService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * « Quitter MemoryLane » : récapitulatif, choix, départ programmé (30 jours,
 * annulable), puis appliqué par memorylane:process-departures.
 */
class AccountDepartureController extends Controller
{
    public function __construct(private DepartureService $departures) {}

    public function show(Request $request)
    {
        $user = $request->user();

        return Inertia::render('Account/Departure', [
            'summary' => $this->departures->accountSummary($user),
            'pending' => $user->departure_requested_at ? [
                'requested_at' => $user->departure_requested_at->toIso8601String(),
                'effective_at' => $user->departure_requested_at->copy()->addDays(DepartureService::GRACE_DAYS)->toDateString(),
                'options' => $user->departure_options,
            ] : null,
            'graceDays' => DepartureService::GRACE_DAYS,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'photos' => ['required', Rule::in(['keep_shared', 'keep_all', 'remove_all'])],
            'voice_notes' => ['required', Rule::in(['keep', 'remove'])],
            'untag_me' => ['required', 'boolean'],
            'successors' => ['nullable', 'array'],
            'successors.*' => ['nullable', 'uuid'],
            // Confirmation explicite : son propre prénom.
            'confirm' => ['required', 'string'],
        ]);
        $first = mb_strtolower(explode(' ', trim($request->user()->name))[0]);
        if (mb_strtolower(trim($data['confirm'])) !== $first) {
            return back()->withErrors(['confirm' => 'Tapez votre prénom pour confirmer.']);
        }
        unset($data['confirm']);

        $this->departures->requestAccountDeparture($request->user(), $data);

        return redirect()->route('account.departure')->with('success', 'Départ programmé : vous pouvez encore l\'annuler.');
    }

    /**
     * « Télécharger mes photos » : archives préparées en tâche de fond,
     * liens envoyés par mail. Une demande toutes les 6 heures au plus.
     */
    public function export(Request $request)
    {
        $user = $request->user();
        if (! \Illuminate\Support\Facades\Cache::add("media-export:{$user->id}", true, now()->addHours(6))) {
            return response()->json(['message' => 'Un export est déjà en préparation : vous recevrez un mail.'], 429);
        }
        \App\Jobs\ExportUserMedia::dispatch($user);

        return response()->json(['message' => 'C\'est parti : vous recevrez un mail avec les liens de téléchargement (quelques minutes à une heure selon le nombre de photos).']);
    }

    public function destroy(Request $request)
    {
        $this->departures->cancelAccountDeparture($request->user());

        return redirect()->route('account.departure')->with('success', 'Départ annulé : rien n\'a changé.');
    }
}
