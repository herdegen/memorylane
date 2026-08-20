<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Foyers (cercles familiaux). Gestion des foyers, de leur appartenance et
 * de leur galerie partagée (pivot household_media).
 */
class HouseholdController extends Controller
{
    public function __construct(protected MediaService $mediaService)
    {
    }

    /**
     * Liste des foyers dont l'utilisateur est membre.
     */
    public function index(Request $request)
    {
        $households = Household::forUser($request->user())
            ->withCount('members')
            ->with('creator:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Household $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'members_count' => $h->members_count,
                'is_creator' => $h->created_by === $request->user()->id,
                'creator_name' => $h->creator?->name,
            ]);

        if ($request->wantsJson()) {
            return response()->json($households);
        }

        return Inertia::render('Households/Index', [
            'households' => $households,
        ]);
    }

    /**
     * Crée un foyer et y ajoute le créateur comme premier membre.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $household = Household::create([
            'name' => $validated['name'],
            'created_by' => $request->user()->id,
        ]);

        $household->members()->attach($request->user()->id);

        if ($request->wantsJson()) {
            return response()->json(['id' => $household->id], 201);
        }

        return redirect()->route('households.show', $household->id);
    }

    /**
     * Détail d'un foyer : membres (et, en 2b, médias partagés).
     */
    public function show(Request $request, Household $household)
    {
        Gate::authorize('view', $household);

        $isCreator = $household->created_by === $request->user()->id;

        $members = $household->members()->orderBy('name')->get()->map(fn (User $u) => [
            'user_id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'is_creator' => $u->id === $household->created_by,
        ]);

        // Galerie du foyer : les médias partagés par les membres, plus
        // récents d'abord. Les URLs passent par les routes protégées
        // (MediaPolicy::view couvre la branche foyer de accessibleBy).
        $media = $household->media()
            ->with(['conversions', 'user:id,name'])
            ->orderByRaw('taken_at DESC NULLS LAST')
            ->orderByDesc('uploaded_at')
            ->get();

        $this->mediaService->hydrateSignedUrls($media);

        $userId = $request->user()->id;
        $media->each(function ($m) use ($userId) {
            $m->is_mine = $m->user_id === $userId;
            $m->shared_by = $m->pivot?->added_by;
        });

        return Inertia::render('Households/Show', [
            'household' => [
                'id' => $household->id,
                'name' => $household->name,
                'is_creator' => $isCreator,
            ],
            'members' => $members,
            'media' => $media,
        ]);
    }

    /**
     * Comptes candidats à l'invitation (recherche nom/email), hors membres.
     */
    public function inviteCandidates(Request $request, Household $household)
    {
        Gate::authorize('manageMembers', $household);

        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $excluded = $household->members()->pluck('users.id')->all();

        $users = User::whereNotIn('id', $excluded)
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email']);

        return response()->json($users);
    }

    /**
     * Ajoute un compte au foyer.
     */
    public function invite(Request $request, Household $household)
    {
        Gate::authorize('manageMembers', $household);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $household->members()->syncWithoutDetaching([
            $validated['user_id'] => ['invited_by' => $request->user()->id],
        ]);

        return response()->json(['message' => 'Membre ajouté.'], 201);
    }

    /**
     * Retire un membre (créateur uniquement ; le créateur ne peut pas se
     * retirer lui-même — il doit supprimer le foyer).
     */
    public function removeMember(Request $request, Household $household, User $user)
    {
        Gate::authorize('manageMembers', $household);

        abort_if($user->id === $household->created_by, 422, 'Le créateur ne peut pas être retiré du foyer.');

        $household->members()->detach($user->id);

        return response()->json(['message' => 'Membre retiré.']);
    }

    /**
     * L'utilisateur quitte le foyer (le créateur doit plutôt le supprimer).
     */
    public function leave(Request $request, Household $household)
    {
        abort_unless($household->isMember($request->user()), 403);
        abort_if(
            $household->created_by === $request->user()->id,
            422,
            'Le créateur ne peut pas quitter le foyer&nbsp;; supprimez-le à la place.'
        );

        $household->members()->detach($request->user()->id);

        return response()->json(['message' => 'Vous avez quitté le foyer.']);
    }

    /**
     * Supprime le foyer (créateur uniquement).
     */
    public function destroy(Request $request, Household $household)
    {
        Gate::authorize('delete', $household);

        $household->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Foyer supprimé.']);
        }

        return redirect()->route('households.index');
    }
}
