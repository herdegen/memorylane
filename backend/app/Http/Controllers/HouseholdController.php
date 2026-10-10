<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\User;
use App\Notifications\Push\HouseholdMemberAdded;
use App\Notifications\Push\PushNotification;
use App\Services\PushNotifier;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Cercles (foyers) famille ou amis : appartenance, galerie partagée (pivot
 * household_media) et cercle courant. Création et gestion des membres
 * réservées aux administrateurs.
 */
class HouseholdController extends Controller
{
    public function __construct(protected MediaService $mediaService)
    {
    }

    /**
     * Cercles de l'utilisateur (l'admin les voit tous, pour les gérer).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $households = ($user->isAdmin() ? Household::query() : Household::forUser($user))
            ->withCount('members')
            ->with('creator:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn (Household $h) => [
                'id' => $h->id,
                'name' => $h->name,
                'kind' => $h->kind,
                'members_count' => $h->members_count,
                'is_member' => $h->isMember($user),
                'is_creator' => $h->created_by === $request->user()->id,
                'creator_name' => $h->creator?->name,
            ]);

        if ($request->wantsJson()) {
            return response()->json($households);
        }

        return Inertia::render('Households/Index', [
            'households' => $households,
            'canManage' => $user->isAdmin(),
            'defaultKind' => config('memorylane.default_mode'),
        ]);
    }

    /**
     * Crée un cercle (admin) ; l'admin en fait partie sauf s'il le refuse.
     */
    public function store(Request $request)
    {
        Gate::authorize('create', Household::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['nullable', 'in:'.Household::KIND_FAMILY.','.Household::KIND_FRIENDS],
            'join' => ['nullable', 'boolean'],
        ]);

        $household = Household::create([
            'name' => $validated['name'],
            'kind' => $validated['kind'] ?? config('memorylane.default_mode'),
            'created_by' => $request->user()->id,
        ]);

        if ($validated['join'] ?? true) {
            $this->addMember($household, $request->user(), $request->user());
        }

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
        $canManage = Gate::allows('manageMembers', $household);

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
                'kind' => $household->kind,
                'is_creator' => $isCreator,
                'is_member' => $household->isMember($request->user()),
                'can_manage' => $canManage,
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
    public function invite(Request $request, Household $household, PushNotifier $push)
    {
        Gate::authorize('manageMembers', $household);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        $invitee = User::findOrFail($validated['user_id']);
        $alreadyMember = $household->isMember($invitee);

        $this->addMember($household, $invitee, $request->user());

        // Push « vous faites partie du foyer » — seulement à un vrai nouvel entrant.
        if (! $alreadyMember) {
            $push->social(
                $invitee,
                new HouseholdMemberAdded(PushNotification::firstName($request->user()->name), $household->name, $household->id),
                $request->user(),
            );
        }

        return response()->json(['message' => 'Membre ajouté.'], 201);
    }

    /**
     * Retire un membre (admin).
     */
    public function removeMember(Request $request, Household $household, User $user)
    {
        Gate::authorize('manageMembers', $household);

        $household->members()->detach($user->id);

        return response()->json(['message' => 'Membre retiré.']);
    }

    /**
     * L'utilisateur quitte le foyer (le créateur doit plutôt le supprimer).
     */
    public function leave(Request $request, Household $household)
    {
        abort_unless($household->isMember($request->user()), 403);

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

    /**
     * Choisit le cercle courant (sélecteur de l'en-tête) : le mode, la liste
     * des personnes, le réseau et les quêtes suivent ce cercle.
     */
    public function switchCurrent(Request $request)
    {
        $validated = $request->validate([
            'household_id' => ['required', 'uuid'],
        ]);

        $user = $request->user();
        abort_unless($user->circles()->contains('id', $validated['household_id']), 403);

        $user->forceFill(['current_household_id' => $validated['household_id']])->save();

        return redirect()->back();
    }

    /**
     * Ajoute un compte au cercle. Dans un cercle d'amis, sa fiche « moi » y
     * est rattachée pour que les autres membres la voient.
     */
    private function addMember(Household $household, User $member, User $by): void
    {
        $household->members()->syncWithoutDetaching([
            $member->id => ['invited_by' => $by->id],
        ]);

        if ($household->isFriends() && $member->person_id) {
            $household->people()->syncWithoutDetaching([
                $member->person_id => ['added_by' => $by->id],
            ]);
        }
    }
}
