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
                'invite_policy' => $h->invite_policy,
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
            'canManage' => Gate::allows('create', Household::class),
            'canCreateFamily' => $user->isAdmin(),
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

        $kind = $validated['kind'] ?? config('memorylane.default_mode');
        // Un membre crée son cercle d'amis ; la famille reste l'affaire de l'admin.
        if (! $request->user()->isAdmin()) {
            abort_if($kind !== Household::KIND_FRIENDS, 403, 'Seul un administrateur crée un cercle famille.');
            $validated['join'] = true;
        }
        $household = Household::create([
            'name' => $validated['name'],
            'kind' => $kind,
            'invite_policy' => Household::defaultInvitePolicy($kind),
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
            // E-mail des membres : visible de ceux qui gèrent le cercle seulement.
            'email' => $canManage ? $u->email : null,
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
                'invite_policy' => $household->invite_policy,
                'can_invite' => Gate::allows('addMembers', $household),
                'is_creator' => $isCreator,
                'is_member' => $household->isMember($request->user()),
                'can_manage' => $canManage,
            ],
            'members' => $members,
            'media' => $media,
            // Cercle d'amis : ses fiches (visibles de ses membres).
            'people' => $household->isFriends()
                ? $household->people()->orderBy('name')->get(['people.id', 'people.name'])
                    ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()
                : [],
        ]);
    }

    /**
     * Comptes candidats à l'invitation (recherche nom/email), hors membres.
     */
    public function inviteCandidates(Request $request, Household $household)
    {
        Gate::authorize('addMembers', $household);

        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $excluded = $household->members()->pluck('users.id')->all();
        $viewer = $request->user();
        $like = '%'.addcslashes($q, '%_\\').'%';

        // Seulement des personnes qui partagent déjà un cercle avec moi
        // (tous pour l'admin) ; e-mail visible de l'admin seul.
        $users = User::realPeople()
            ->visibleTo($viewer)
            ->whereNotIn('id', $excluded)
            ->where(fn ($w) => $w->where('name', 'ilike', $like)
                ->when($viewer->isAdmin(), fn ($w) => $w->orWhere('email', 'ilike', $like)))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $viewer->isAdmin() ? $u->email : null]);

        return response()->json($users);
    }

    /**
     * Ajoute un compte au foyer.
     */
    public function invite(Request $request, Household $household, PushNotifier $push)
    {
        Gate::authorize('addMembers', $household);

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
    /** Ce que le retrait de ce membre concerne (mêmes choix qu'un départ). */
    public function removalSummary(Request $request, Household $household, User $user)
    {
        Gate::authorize('manageMembers', $household);
        abort_unless($household->isMember($user), 404);

        return response()->json(app(\App\Services\DepartureService::class)->circleSummary($user, $household));
    }

    /**
     * Retirer un membre : comme un départ, mais c'est le responsable qui
     * choisit (ses photos restent au cercle ou en sont retirées, successeur
     * s'il avait créé le groupe) ; la personne est prévenue par mail.
     */
    public function removeMember(Request $request, Household $household, User $user)
    {
        Gate::authorize('manageMembers', $household);
        abort_unless($household->isMember($user), 404);
        abort_if($user->id === $request->user()->id, 422, 'Pour vous-même, utilisez « Quitter le cercle ».');
        $data = $request->validate([
            'photos' => ['nullable', \Illuminate\Validation\Rule::in(['keep', 'withdraw'])],
            'successor_id' => ['nullable', 'uuid'],
        ]);
        $photos = $data['photos'] ?? 'keep';

        app(\App\Services\DepartureService::class)->leaveCircle($user, $household, $photos, $data['successor_id'] ?? null);

        if (! $user->isMemorial() && $user->email) {
            $user->notify(new \App\Notifications\RemovedFromCircle($household->name, $request->user()->name, $photos === 'withdraw'));
        }

        return response()->json(['message' => 'Membre retiré.']);
    }

    /**
     * L'utilisateur quitte le foyer (le créateur doit plutôt le supprimer).
     */
    /** Ce que le départ de ce cercle concerne (écran de confirmation). */
    public function leaveSummary(Request $request, Household $household)
    {
        abort_unless($household->isMember($request->user()), 403);

        return response()->json(app(\App\Services\DepartureService::class)->circleSummary($request->user(), $household));
    }

    /**
     * Quitter le cercle : mes photos et albums partagés y restent (« keep »)
     * ou en sont retirés (« withdraw ») ; le créateur d'un groupe d'amis
     * désigne qui le reprend ; le dernier membre ferme le groupe.
     */
    public function leave(Request $request, Household $household)
    {
        abort_unless($household->isMember($request->user()), 403);
        $data = $request->validate([
            'photos' => ['nullable', \Illuminate\Validation\Rule::in(['keep', 'withdraw'])],
            'successor_id' => ['nullable', 'uuid'],
        ]);

        app(\App\Services\DepartureService::class)->leaveCircle(
            $request->user(), $household, $data['photos'] ?? 'keep', $data['successor_id'] ?? null,
        );

        return response()->json(['message' => 'Vous avez quitté le cercle.']);
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
     * Réglages du cercle (admin) : nom, qui peut inviter.
     */
    public function update(Request $request, Household $household)
    {
        Gate::authorize('update', $household);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'invite_policy' => ['sometimes', 'in:'.Household::INVITE_EVERYONE.','.Household::INVITE_ADMIN],
        ]);

        $household->update($validated);

        return response()->json(['message' => 'Cercle mis à jour.']);
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

        // Depuis la page d'un album (propre au cercle quitté) : la liste des
        // albums du nouveau cercle plutôt que cet album.
        $previous = (string) parse_url(url()->previous(), PHP_URL_PATH);
        if (preg_match('#^/albums/[^/]+/?$#', $previous)) {
            return redirect()->route('albums.index');
        }

        // Depuis la fiche d'une personne absente du nouveau cercle : retour à
        // la liste (Personnes / Amis) du cercle choisi.
        if (preg_match('#^/people/([0-9a-f-]{36})/?$#i', $previous, $m)) {
            $fresh = $user->fresh();
            if (! \App\Models\Person::inContext($fresh)->whereKey($m[1])->exists()) {
                return redirect()->route('people.index')
                    ->with('success', "Cette personne ne fait pas partie de « {$fresh->currentCircle()?->name} ».");
            }
        }

        return redirect()->back();
    }

    /**
     * Ajoute un compte au cercle. Dans un cercle d'amis, sa fiche « moi » y
     * est rattachée pour que les autres membres la voient.
     */
    private function addMember(Household $household, User $member, User $by): void
    {
        $household->addMember($member, $by);
    }

    /**
     * Fiches existantes rattachables à un cercle d'amis (recherche par nom,
     * hors fiches déjà rattachées). Admin : il voit toutes les fiches.
     */
    public function personCandidates(Request $request, Household $household)
    {
        Gate::authorize('manageMembers', $household);
        abort_unless($household->isFriends(), 422, 'Seuls les cercles d\'amis ont des fiches rattachées.');

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $people = \App\Models\Person::visibleTo($request->user())
            ->whereNotIn('people.id', $household->people()->pluck('people.id'))
            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%'])
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name']);

        return response()->json($people);
    }

    /**
     * Rattache une fiche existante à un cercle d'amis : ses membres la voient
     * désormais (elle reste dans le répertoire familial si elle y était).
     */
    public function addPerson(Request $request, Household $household)
    {
        Gate::authorize('manageMembers', $household);
        abort_unless($household->isFriends(), 422, 'Seuls les cercles d\'amis ont des fiches rattachées.');

        $validated = $request->validate([
            'person_id' => ['required', 'uuid', new \App\Rules\VisiblePerson],
        ]);

        $household->people()->syncWithoutDetaching([
            $validated['person_id'] => ['added_by' => $request->user()->id],
        ]);

        return response()->json(['message' => 'Fiche ajoutée au cercle.'], 201);
    }

    public function removePerson(Request $request, Household $household, string $personId)
    {
        Gate::authorize('manageMembers', $household);

        $household->people()->detach($personId);

        return response()->json(['message' => 'Fiche retirée du cercle.']);
    }
}
