<?php

namespace App\Http\Controllers;

use App\Rules\VisiblePerson;
use App\Models\Album;
use App\Models\Invitation;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Media;
use App\Models\User;
use App\Services\GenealogyService;
use App\Services\InvitationService;
use App\Services\MediaService;
use App\Services\Vision\AvatarFacePositionService;
use App\Services\Vision\FaceCropService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PersonController extends Controller
{
    protected MediaService $mediaService;

    protected GenealogyService $genealogy;

    protected FaceCropService $faceCrops;

    protected AvatarFacePositionService $avatarPositions;

    public function __construct(MediaService $mediaService, GenealogyService $genealogy, FaceCropService $faceCrops, AvatarFacePositionService $avatarPositions)
    {
        $this->mediaService = $mediaService;
        $this->genealogy = $genealogy;
        $this->faceCrops = $faceCrops;
        $this->avatarPositions = $avatarPositions;
    }

    public function index(Request $request)
    {
        // Arbre/personnes publics : tous les comptes connectés voient les fiches
        // (lecture). L'écriture reste réservée au propriétaire.
        // Fiches du cercle courant (famille : répertoire familial ; amis :
        // fiches du cercle) — sert aussi aux sélecteurs de personne.
        $people = Person::inContext($request->user())
            ->withCount('media')
            ->withMatchedFacesCount()
            ->with('avatar:id')
            ->orderBy('name')
            ->get();

        // Proximité généalogique : distance (BFS) depuis la fiche « moi » de
        // l'utilisateur, sur le graphe parents/enfants/conjoints. Sert au tri.
        $selfPersonId = auth()->user()->person_id;
        $proximity = $this->genealogy->proximity($selfPersonId);
        // Libellé de parenté (« votre fils », « votre tante ») pour les proches
        // à 4 liens au plus — liste téléphone. Jamais entre amis.
        $paths = ($selfPersonId && $request->user()->mode() !== 'friends' && ! $request->wantsJson())
            ? $this->genealogy->pathsFrom($selfPersonId, 4)
            : [];

        // Annuaire allégé : seuls les champs lus par la page et les sélecteurs
        // de personne (plus de notes, lieux, ni photo de profil complète).
        $people = $people->map(fn (Person $person) => [
            ...Arr::only($person->attributesToArray(), [
                'id', 'name', 'first_name', 'last_name', 'maiden_name', 'gender',
                'birth_date', 'death_date', 'media_count', 'matched_faces_count',
            ]),
            'avatar_url' => $this->resolveAvatarUrl($person),
            'proximity' => $proximity['distance'][$person->id] ?? null,
            'relatives_count' => $proximity['degree'][$person->id] ?? 0,
            'is_self' => $person->id === $selfPersonId,
            'relation_label' => isset($paths[$person->id])
                ? \App\Services\GenealogyService::relationLabel($paths[$person->id], $person->gender)
                : null,
        ]);

        if ($request->wantsJson()) {
            // Sélecteurs : on propose aussi les fiches visibles d'AUTRES
            // cercles (marquées « elsewhere ») pour éviter les doublons — les
            // choisir propose de les ajouter au cercle courant.
            if ($request->boolean('with_elsewhere')) {
                $people = $people->concat($this->elsewherePeople($request->user(), $people->pluck('id')));
            }

            return response()->json($people);
        }

        return Inertia::render('People/Index', [
            'people' => $people,
            'selfPersonId' => $selfPersonId,
        ]);
    }

    /**
     * Fiches visibles hors du cercle courant, avec l'endroit où elles sont
     * (« Famille » ou le nom d'un cercle d'amis du viewer).
     */
    private function elsewherePeople(User $user, $excludeIds)
    {
        $friendCircles = $user->circles()->where('kind', \App\Models\Household::KIND_FRIENDS)->keyBy('id');

        return Person::visibleTo($user)
            ->whereNotIn('people.id', $excludeIds)
            ->withMatchedFacesCount()
            ->with(['avatar:id', 'circles:id'])
            ->orderBy('name')
            ->get()
            ->map(fn (Person $person) => [
                ...Arr::only($person->attributesToArray(), [
                    'id', 'name', 'first_name', 'last_name', 'maiden_name', 'gender', 'birth_date', 'death_date',
                ]),
                'avatar_url' => $this->resolveAvatarUrl($person),
                'elsewhere' => true,
                'elsewhere_label' => $person->in_family_directory
                    ? 'Famille'
                    : ($person->circles->map(fn ($c) => $friendCircles->get($c->id)?->name)->filter()->first() ?? 'Autre cercle'),
            ]);
    }

    /**
     * Désigne la personne comme étant l'utilisateur connecté (« c'est moi »),
     * point de référence du tri par proximité.
     */
    public function setSelf(Request $request, Person $person)
    {
        abort_unless($this->canSetSelf($person), 403);

        $user = auth()->user();
        $user->person_id = $user->person_id === $person->id ? null : $person->id;
        $user->save();

        if ($request->wantsJson()) {
            return response()->json(['person_id' => $user->person_id]);
        }

        return redirect()->back()->with('success', $user->person_id ? 'Vous êtes défini sur cette personne' : 'Référence retirée');
    }

    /**
     * Compat : les appels qui n'envoient qu'un `name` complet sont découpés
     * en prénom(s) + nom (dernier mot). Les appels modernes envoient
     * first_name / last_name directement.
     */
    protected function normalizeNameFields(array $validated): array
    {
        if (empty($validated['first_name']) && empty($validated['last_name']) && ! empty($validated['name'])) {
            $parts = preg_split('/\s+/', trim($validated['name']));
            if (count($parts) > 1) {
                $validated['last_name'] = array_pop($parts);
                $validated['first_name'] = implode(' ', $parts);
            } else {
                $validated['first_name'] = $parts[0];
                $validated['last_name'] = null;
            }
        }

        return $validated;
    }

    protected function personRules(): array
    {
        return [
            'name' => 'required_without:first_name|nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'gender' => 'nullable|in:M,F,U',
            'maiden_name' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'birth_place' => 'nullable|string|max:255',
            'death_date' => 'nullable|date|after_or_equal:birth_date',
            'death_place' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'avatar_media_id' => 'nullable|exists:media,id',
            'notes' => 'nullable|string|max:2000',
            'father_id' => ['nullable', new VisiblePerson],
            'mother_id' => ['nullable', new VisiblePerson],
        ];
    }

    public function store(Request $request)
    {
        $validated = $this->normalizeNameFields($request->validate($this->personRules()));

        // Photo de profil : uniquement une photo que l'on peut voir (elle est
        // ensuite servie à tous les comptes qui voient la fiche).
        if (! empty($validated['avatar_media_id'])) {
            Gate::authorize('view', Media::findOrFail($validated['avatar_media_id']));
        }

        // Créée depuis un cercle d'amis : la fiche lui appartient (visible de
        // ses membres) et reste hors du répertoire familial.
        $circle = $request->user()->currentCircle();
        $inFriends = $circle?->isFriends() ?? false;

        $person = Person::create([
            ...$validated,
            'user_id' => auth()->id(),
            'in_family_directory' => ! $inFriends,
        ]);

        if ($inFriends) {
            $person->circles()->attach($circle->id, ['added_by' => auth()->id()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Personne creee',
                'person' => $person,
            ], 201);
        }

        return redirect()->route('people.show', $person)
            ->with('success', 'Personne creee');
    }

    public function show(Request $request, Person $person)
    {
        // Fiche publique en lecture (arbre public) ; la liste des médias reste
        // scopée aux médias accessibles au visiteur (cf. plus bas).
        $person->load(['avatar.conversions', 'father', 'mother']);
        $person->loadCount(array_merge(['media'], self::matchedFacesCount()));

        $person->avatar_url = $this->resolveAvatarUrl($person);

        // L'adresse ($hidden par défaut) n'apparaît dans la réponse que si le
        // visiteur y a droit — le front se contente de tester sa présence.
        if ($this->canSeeAddress($person)) {
            $person->makeVisible(['address', 'address_city']);
        }

        // Père / mère avec miniature (avatar_url) pour la navigation.
        $viewer = $request->user();
        $father = $person->father?->isVisibleTo($viewer) ? $this->hydrateAvatar($person->father) : null;
        $mother = $person->mother?->isVisibleTo($viewer) ? $this->hydrateAvatar($person->mother) : null;
        // Ne pas laisser fuiter un parent d'un autre cercle via la relation.
        $person->setRelation('father', $father);
        $person->setRelation('mother', $mother);

        // Frères et sœurs : partagent père OU mère, en excluant la personne.
        $siblings = collect();
        if ($person->father_id || $person->mother_id) {
            $siblings = Person::visibleTo($viewer)->where('id', '!=', $person->id)
                ->where(function ($q) use ($person) {
                    if ($person->father_id) {
                        $q->orWhere('father_id', $person->father_id);
                    }
                    if ($person->mother_id) {
                        $q->orWhere('mother_id', $person->mother_id);
                    }
                })
                ->with('avatar.conversions')
                ->withMatchedFacesCount()
                ->get()
                ->each(fn ($s) => $s->avatar_url = $this->resolveAvatarUrl($s));
        }

        // Load children
        $children = Person::visibleTo($viewer)
            ->where(fn ($q) => $q->where('father_id', $person->id)->orWhere('mother_id', $person->id))
            ->with('avatar.conversions')
            ->withMatchedFacesCount()
            ->get();

        $children->transform(function ($child) {
            $child->avatar_url = $this->resolveAvatarUrl($child);
            return $child;
        });

        // Load spouses (bidirectional)
        $spouseIds = DB::table('person_relationships')
            ->where(function ($q) use ($person) {
                $q->where('person1_id', $person->id)
                    ->orWhere('person2_id', $person->id);
            })
            ->get()
            ->map(fn ($r) => $r->person1_id === $person->id ? $r->person2_id : $r->person1_id);

        $spouses = Person::visibleTo($viewer)->whereIn('id', $spouseIds)
            ->with('avatar.conversions')
            ->withMatchedFacesCount()
            ->get();

        $spouses->transform(function ($spouse) {
            $spouse->avatar_url = $this->resolveAvatarUrl($spouse);
            return $spouse;
        });

        // Cadrage intelligent (issue #51) : object-position centré sur le
        // visage pour les avatars « photo entière », calculé en un lot pour
        // toutes les cartes du mini-arbre Famille.
        $everyone = collect([$person, $father, $mother])->filter()
            ->concat($siblings)->concat($children)->concat($spouses);
        $facePositions = $this->avatarPositions->forPeople($everyone);
        $everyone->each(fn ($p) => $p->avatar_position = $facePositions[$p->id] ?? null);

        // Médias de la personne visibles PAR LE VISITEUR uniquement (la galerie
        // reste privée : ne pas fuiter les photos privées via la fiche, surtout
        // quand l'arbre sera public).
        // On charge TOUS les médias accessibles de la personne (volume borné à
        // l'échelle d'une personne) pour pouvoir les regrouper par album puis
        // par année (issue #32) plutôt qu'une grille à plat paginée.
        $media = $person->media()
            ->accessibleBy(auth()->user())
            ->with(['conversions', 'tags'])
            ->orderBy('taken_at', 'desc')
            ->orderBy('uploaded_at', 'desc')
            ->get();

        $this->mediaService->hydrateSignedUrls($media);

        $mediaGroups = $this->groupPersonMedia($media);

        if ($request->wantsJson()) {
            return response()->json([
                'person' => $person,
                'media' => $media->values(),
                'mediaGroups' => $mediaGroups,
                'children' => $children,
                'spouses' => $spouses,
                'siblings' => $siblings,
            ]);
        }

        return Inertia::render('People/Show', [
            'person' => $person,
            'media' => $media->values(),
            'mediaGroups' => $mediaGroups,
            'father' => $father,
            'mother' => $mother,
            'children' => $children,
            'spouses' => $spouses,
            'siblings' => $siblings,
            'isSelf' => auth()->user()->person_id === $person->id,
            // « votre fille », « votre tante »… depuis la fiche « moi » du visiteur
            'relationLabel' => $this->viewerRelationLabel($request->user(), $person),
            'canManage' => $this->canManage($person),
            'canDelete' => $this->isOwnerOrAdmin($person),
            'canSetSelf' => $this->canSetSelf($person),
            'account' => $this->accountPayload($person),
        ]);
    }

    /**
     * Parenté du visiteur avec la fiche (4 liens au plus), jamais entre amis.
     */
    private function viewerRelationLabel(\App\Models\User $user, Person $person): ?string
    {
        $selfId = $user->person_id;
        if (! $selfId || $selfId === $person->id || $user->mode() === 'friends') {
            return null;
        }
        $paths = $this->genealogy->pathsFrom($selfId, 4);

        return isset($paths[$person->id])
            ? \App\Services\GenealogyService::relationLabel($paths[$person->id], $person->gender)
            : null;
    }

    /**
     * Regroupe les médias d'une personne pour sa fiche (issue #32) : une section
     * par album ACCESSIBLE au visiteur contenant ces médias (album le plus récent
     * d'abord), puis le reste « hors album » regroupé par année (récent d'abord,
     * « sans date » en fin). Les sections ne portent que des IDs ; le front les
     * résout via la liste plate `media`. Un média dans plusieurs albums apparaît
     * dans chacun.
     *
     * @param  \Illuminate\Support\Collection<int, Media>  $media
     * @return array{albums: array<int, array{id: string, name: string, media_ids: array<int, string>}>, by_year: array<int, array{year: ?string, media_ids: array<int, string>}>}
     */
    private function groupPersonMedia(\Illuminate\Support\Collection $media): array
    {
        if ($media->isEmpty()) {
            return ['albums' => [], 'by_year' => []];
        }

        $accessibleAlbumIds = Album::accessibleBy(auth()->user())->pluck('id');

        $linksByMedia = DB::table('album_media')
            ->join('albums', 'albums.id', '=', 'album_media.album_id')
            ->whereIn('album_media.media_id', $media->pluck('id'))
            ->whereIn('albums.id', $accessibleAlbumIds)
            ->get(['album_media.media_id', 'albums.id as album_id', 'albums.name as album_name'])
            ->groupBy('media_id');

        $albums = [];
        $inAlbum = [];

        foreach ($media as $m) {
            $links = $linksByMedia->get($m->id);
            if (! $links) {
                continue;
            }
            $inAlbum[$m->id] = true;
            $ts = ($m->taken_at ?? $m->uploaded_at)?->getTimestamp() ?? 0;

            foreach ($links as $link) {
                $albums[$link->album_id] ??= [
                    'id'        => $link->album_id,
                    'name'      => $link->album_name,
                    'media_ids' => [],
                    'latest'    => 0,
                ];
                $albums[$link->album_id]['media_ids'][] = $m->id;
                $albums[$link->album_id]['latest'] = max($albums[$link->album_id]['latest'], $ts);
            }
        }

        $albumSections = collect($albums)
            ->sortByDesc('latest')
            ->map(fn ($a) => ['id' => $a['id'], 'name' => $a['name'], 'media_ids' => $a['media_ids']])
            ->values()
            ->all();

        // Hors album, regroupé par année.
        $byYear = [];
        $undated = [];
        foreach ($media as $m) {
            if (isset($inAlbum[$m->id])) {
                continue;
            }
            $date = $m->taken_at ?? $m->uploaded_at;
            if ($date) {
                $byYear[(int) $date->format('Y')][] = $m->id;
            } else {
                $undated[] = $m->id;
            }
        }
        krsort($byYear); // années décroissantes

        $yearSections = [];
        foreach ($byYear as $year => $ids) {
            $yearSections[] = ['year' => (string) $year, 'media_ids' => $ids];
        }
        if ($undated) {
            $yearSections[] = ['year' => null, 'media_ids' => $undated];
        }

        return ['albums' => $albumSections, 'by_year' => $yearSections];
    }

    /**
     * Charge l'avatar (et le compteur de visages matchés pour le fallback) d'une
     * personne et lui attache une avatar_url. Renvoie null si $person est null.
     */
    private function hydrateAvatar(?Person $person): ?Person
    {
        if (! $person) {
            return null;
        }

        $person->loadMissing('avatar.conversions');
        $person->loadCount(self::matchedFacesCount());
        $person->avatar_url = $this->resolveAvatarUrl($person);

        return $person;
    }

    /**
     * Lien de parenté entre la fiche « moi » du visiteur et cette personne :
     * plus court chemin dans le graphe familial + libellés français (pour la
     * marche animée dans l'arbre).
     */
    public function kinship(Request $request, Person $person)
    {
        $selfId = $request->user()->person_id;
        abort_unless($selfId, 422, "Reliez d'abord votre compte à votre fiche (bouton « C'est moi »).");
        abort_if($selfId === $person->id, 422, "C'est votre propre fiche.");

        $path = app(\App\Services\GenealogyService::class)->pathBetween($selfId, $person->id);

        if (! $path) {
            return response()->json(['found' => false]);
        }

        $people = Person::visibleTo($request->user())->whereIn('id', $path['ids'])
            ->with('avatar.conversions')
            ->withMatchedFacesCount()
            ->get()
            ->keyBy('id');

        // Un chemin qui traverse une fiche invisible (autre cercle) n'est pas montré.
        if ($people->count() !== count(array_unique($path['ids']))) {
            return response()->json(['found' => false]);
        }

        $orderedPath = collect($path['ids'])->map(fn ($id) => [
            'id' => $id,
            'name' => $people[$id]->name ?? '?',
            'gender' => $people[$id]->gender ?? 'U',
            'avatar_url' => isset($people[$id]) ? $this->resolveAvatarUrl($people[$id]) : null,
        ])->values();

        // Légende de chaque pas, genrée sur la personne d'ARRIVÉE du pas.
        $stepLabels = collect($path['edges'])->map(function ($type, $i) use ($orderedPath) {
            $gender = $orderedPath[$i + 1]['gender'];
            return match ($type) {
                'parent' => $gender === 'F' ? 'sa mère' : ($gender === 'M' ? 'son père' : 'son parent'),
                'child' => $gender === 'F' ? 'sa fille' : ($gender === 'M' ? 'son fils' : 'son enfant'),
                default => $gender === 'F' ? 'sa conjointe' : ($gender === 'M' ? 'son conjoint' : 'son conjoint'),
            };
        })->values();

        // Ancêtre commun = point le plus « haut » du chemin en générations
        // (monter = parent, descendre = enfant, conjoint = même niveau). La
        // vue finale de l'arbre se centre dessus : ses deux branches (vers
        // moi et vers la personne) sont alors visibles en même temps.
        $level = 0;
        $best = 0;
        $apexIndex = 0;
        foreach ($path['edges'] as $i => $type) {
            $level += $type === 'parent' ? 1 : ($type === 'child' ? -1 : 0);
            if ($level > $best) {
                $best = $level;
                $apexIndex = $i + 1;
            }
        }

        return response()->json([
            'found' => true,
            'steps' => count($path['edges']),
            'relation_label' => \App\Services\GenealogyService::relationLabel($path['edges'], $person->gender),
            'path' => $orderedPath,
            'edge_labels' => $stepLabels,
            'apex_index' => $apexIndex,
        ]);
    }

    /**
     * Frise de vie : fusion d'événements auto-déduits (naissance, mariages,
     * naissances des enfants, décès), des moments libres (life_events) et de
     * toutes les photos datées de la personne, triés par date croissante.
     */
    public function timeline(Person $person)
    {
        $items = [];
        $viewer = auth()->user();

        if ($person->birth_date) {
            $items[] = $this->autoEvent('birth', $person->birth_date, 'Naissance', $person->birth_place);
        }
        if ($person->death_date) {
            $items[] = $this->autoEvent('death', $person->death_date, 'Décès', $person->death_place);
        }

        // Albums accessibles au visiteur : calculés une fois pour toute la
        // frise (moments liés à un album, albums des photos).
        $accessibleAlbumIds = Album::accessibleBy($viewer)->pluck('id');

        // Mariages / unions (dates portées par le pivot person_relationships)
        $spouseRelations = fn ($q) => $q->with('avatar:id')->withMatchedFacesCount();
        $person->load(['spousesAsFirst' => $spouseRelations, 'spousesAsSecond' => $spouseRelations]);
        foreach ($person->spouses as $spouse) {
            if (! $spouse->isVisibleTo($viewer)) {
                continue;
            }
            $start = $spouse->pivot->start_date ?? null;
            if ($start) {
                $label = ($spouse->pivot->type ?? 'spouse') === 'partner' ? 'Union avec ' : 'Mariage avec ';
                $items[] = array_merge(
                    $this->autoEvent('marriage', $start, $label . $spouse->name, $spouse->pivot->start_place ?? null),
                    ['related' => $this->relatedPayload($spouse)]
                );
            }
        }

        // Naissances des enfants
        $children = Person::visibleTo($viewer)
            ->where(fn ($q) => $q->where('father_id', $person->id)->orWhere('mother_id', $person->id))
            ->whereNotNull('birth_date')
            ->with('avatar:id')
            ->withMatchedFacesCount()
            ->get();
        foreach ($children as $child) {
            $items[] = array_merge(
                $this->autoEvent('child', $child->birth_date, 'Naissance de ' . $child->name, $child->birth_place),
                ['related' => $this->relatedPayload($child)]
            );
        }

        // Moments libres
        $lifeEvents = $person->lifeEvents()->with([
            // Photo principale du moment : seulement si le visiteur la voit.
            'media' => fn ($q) => $q->accessibleBy(auth()->user())->with('conversions'),
            'album',
            'photos' => fn ($q) => $q->accessibleBy(auth()->user())->where('type', 'photo')->with('conversions'),
        ])->get();
        foreach ($lifeEvents as $ev) {
            $items[] = [
                'date' => optional($ev->event_date)->format('Y-m-d'),
                'end_date' => optional($ev->end_date)->format('Y-m-d'),
                'date_precision' => $ev->date_precision ?? 'day',
                'kind' => $ev->type,
                'title' => $ev->title,
                'description' => $ev->description,
                'place' => $ev->place,
                // Lieu géolocalisé (animation carte du diaporama à venir).
                'latitude' => $ev->latitude,
                'longitude' => $ev->longitude,
                'media' => $ev->media ? $this->mediaPayload($ev->media) : null,
                // Galerie du moment (photos rattachées directement).
                // Jusqu'à 24 (la limite du formulaire) : en dessous, modifier le
                // moment aurait retiré les photos non chargées.
                'photos' => $ev->photos->take(24)->map(fn (Media $m) => $this->mediaPayload($m))->values()->all(),
                // Album lié (avec ses photos : la galerie du moment, pour la
                // rafale du récit de vie), seulement s'il est accessible au visiteur.
                'album' => $ev->album && $accessibleAlbumIds->contains($ev->album->id)
                    ? $this->albumPayload($ev->album)
                    : null,
                'life_event_id' => $ev->id,
                'can_delete' => $viewer->isAdmin() || $ev->user_id === $viewer->id || $person->user_id === $viewer->id,
            ];
        }

        // Photos datées, visibles par le visiteur, avec leurs albums accessibles
        // (le front regroupe la frise par album puis par année, issue #32).
        $photos = $person->media()
            ->accessibleBy(auth()->user())
            ->whereNotNull('taken_at')
            ->with('conversions')
            ->orderBy('taken_at')
            ->get();

        $albumsByMedia = collect();
        if ($photos->isNotEmpty()) {
            $albumsByMedia = DB::table('album_media')
                ->join('albums', 'albums.id', '=', 'album_media.album_id')
                ->whereIn('album_media.media_id', $photos->pluck('id'))
                ->whereIn('albums.id', $accessibleAlbumIds)
                ->get(['album_media.media_id', 'albums.id as album_id', 'albums.name as album_name'])
                ->groupBy('media_id');
        }

        foreach ($photos as $m) {
            $items[] = [
                'date' => optional($m->taken_at)->format('Y-m-d'),
                'kind' => 'photo',
                'title' => $m->original_name,
                'media' => $this->mediaPayload($m),
                'albums' => collect($albumsByMedia->get($m->id) ?? [])
                    ->map(fn ($l) => ['id' => $l->album_id, 'name' => $l->album_name])
                    ->values()->all(),
            ];
        }

        $items = collect($items)
            ->filter(fn ($i) => ! empty($i['date']))
            ->sortBy('date')
            ->values();

        // Enrichissement « récit de vie » : coordonnées géocodées depuis le
        // lieu en texte (naissance, mariage…) et photo du lieu (Wikimedia
        // Commons) pour les événements datés. Les deux services cachent
        // durablement : seul le tout premier affichage paie les appels.
        // Exclusions : photos (elles ont leurs propres métadonnées) et
        // naissances d'enfants (déplacer la carte n'y a pas de sens).
        // Les appels externes (Nominatim, Wikipédia, Commons) sont lents et
        // en série : ici on ne lit QUE le cache. Ce qui manque part dans un
        // job (`place_pending`), et le front recharge la frise peu après.
        $geocoder = app(\App\Services\GeocodeService::class);
        $placePhotos = app(\App\Services\PlacePhotoService::class);
        $pending = [];
        $items = $items->map(function ($item) use ($geocoder, $placePhotos, &$pending) {
            if (in_array($item['kind'] ?? '', ['photo', 'child'], true)) {
                return $item;
            }
            // Lieux GEDCOM du type « Croix, , , , » : virgules vides nettoyées.
            if (! empty($item['place'])) {
                $item['place'] = trim(preg_replace('/(\s*,\s*)+/', ', ', $item['place']), " \t,");
            }
            if (empty($item['latitude']) && ! empty($item['place'])) {
                if (! $geocoder->isResolved($item['place'])) {
                    $item['place_pending'] = true;
                    $pending[] = ['place' => $item['place'], 'latitude' => null, 'longitude' => null];

                    return $item;
                }
                if ($coords = $geocoder->coordinatesFor($item['place'])) {
                    $item['latitude'] = $coords['latitude'];
                    $item['longitude'] = $coords['longitude'];
                }
            }
            if (! empty($item['latitude']) && ! empty($item['longitude'])) {
                $lat = (float) $item['latitude'];
                $lng = (float) $item['longitude'];
                if (! $placePhotos->isResolved($lat, $lng, $item['place'] ?? null)) {
                    $item['place_pending'] = true;
                    $pending[] = ['place' => $item['place'] ?? null, 'latitude' => $lat, 'longitude' => $lng];

                    return $item;
                }
                $item['place_photo_url'] = $placePhotos->photoFor($lat, $lng, $item['place'] ?? null);
            }

            return $item;
        });

        \App\Jobs\EnrichTimelinePlaces::dispatchOnce(array_values(array_unique($pending, SORT_REGULAR)));

        return response()->json($items);
    }

    private function autoEvent(string $kind, $date, string $title, ?string $place): array
    {
        return [
            'date' => $date ? \Illuminate\Support\Carbon::parse($date)->format('Y-m-d') : null,
            'kind' => $kind,
            'title' => $title,
            'place' => $place,
        ];
    }

    private function relatedPayload(Person $person): array
    {
        // Relations préchargées par l'appelant quand il boucle (pas de N+1).
        $person->loadMissing('avatar:id');
        if (! array_key_exists('matched_faces_count', $person->getAttributes())) {
            $person->loadCount(self::matchedFacesCount());
        }

        return [
            'id' => $person->id,
            'name' => $person->name,
            'avatar_url' => $this->resolveAvatarUrl($person),
        ];
    }

    /** Nombre maximal de photos d'album embarquées dans un moment de la frise. */
    private const ALBUM_MEDIA_LIMIT = 12;

    /**
     * Album lié à un moment : identité + ses premières photos accessibles au
     * visiteur (ordre chronologique de l'album).
     */
    private function albumPayload(Album $album): array
    {
        $media = $album->media()
            ->accessibleBy(auth()->user())
            ->where('type', 'photo')
            ->with('conversions')
            ->limit(self::ALBUM_MEDIA_LIMIT)
            ->get();

        return [
            'id' => $album->id,
            'name' => $album->name,
            'media' => $media->map(fn (Media $m) => $this->mediaPayload($m))->values()->all(),
        ];
    }

    private function mediaPayload(Media $media): array
    {
        $media->loadMissing('conversions');
        $thumb = $media->conversions->firstWhere('conversion_name', 'thumbnail')
            ?? $media->conversions->firstWhere('conversion_name', 'small');
        $medium = $media->conversions->firstWhere('conversion_name', 'medium')
            ?? $media->conversions->firstWhere('conversion_name', 'web');

        return [
            'id' => $media->id,
            'type' => $media->type,
            'original_name' => $media->original_name,
            'title' => $media->title,
            'taken_at' => optional($media->taken_at)->toIso8601String(),
            'url' => $this->mediaService->fileUrl($media),
            'thumbnail_url' => $thumb ? $this->mediaService->fileUrl($media, $thumb->conversion_name) : null,
            'medium_url' => $medium ? $this->mediaService->fileUrl($media, $medium->conversion_name) : null,
        ];
    }

    public function update(Request $request, Person $person)
    {
        // Fiches ouvertes en écriture à tous les membres (historique pour
        // annuler, cf. PersonObserver).
        $this->authorizeManage($person);

        $validated = $this->normalizeNameFields($request->validate($this->personRules()));

        // L'adresse est confidentielle : un membre qui ne la voit pas ne peut
        // ni la lire ni l'effacer (le formulaire l'enverrait vide).
        if (! $this->canSeeAddress($person)) {
            unset($validated['address']);
        }

        // Photo de profil : uniquement une photo que le visiteur peut voir.
        if (! empty($validated['avatar_media_id']) && $validated['avatar_media_id'] !== $person->avatar_media_id) {
            Gate::authorize('view', Media::findOrFail($validated['avatar_media_id']));
        }

        $this->ensureNoCycle($person->id, array_intersect_key($validated, array_flip(['father_id', 'mother_id'])));

        $person->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Personne mise a jour',
                'person' => $person,
            ]);
        }

        return redirect()->back()->with('success', 'Personne mise a jour');
    }

    public function destroy(Request $request, Person $person)
    {
        // Suppression réservée au créateur de la fiche et aux admins.
        abort_unless($this->isOwnerOrAdmin($person), 403);

        $person->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Personne supprimee',
            ]);
        }

        return redirect()->route('people.index')
            ->with('success', 'Personne supprimee');
    }

    public function attachToMedia(Request $request)
    {
        $validated = $request->validate([
            'media_id' => 'required|exists:media,id',
            'person_id' => ['required', new VisiblePerson],
        ]);

        $media = Media::findOrFail($validated['media_id']);
        $person = Person::findOrFail($validated['person_id']);

        // Le média doit être à soi ; les fiches sont communes à la famille.
        abort_unless($media->user_id === auth()->id(), 403);
        app(\App\Services\Vision\FacePlausibility::class)->assertPossible($person, $media);

        if (!$media->people()->where('person_id', $person->id)->exists()) {
            $media->people()->attach($person->id);
        }

        return response()->json([
            'message' => 'Personne ajoutee au media',
        ]);
    }

    public function detachFromMedia(Request $request)
    {
        $validated = $request->validate([
            'media_id' => 'required|exists:media,id',
            'person_id' => ['required', new VisiblePerson],
        ]);

        $media = Media::findOrFail($validated['media_id']);
        $person = Person::findOrFail($validated['person_id']);

        // Le média doit être à soi ; les fiches sont communes à la famille.
        abort_unless($media->user_id === auth()->id(), 403);

        $media->people()->detach($person->id);

        return response()->json([
            'message' => 'Personne retiree du media',
        ]);
    }

    /**
     * Refuse un parent qui descend déjà de l'enfant : la fiche deviendrait
     * son propre ancêtre (boucle qui casse l'arbre et les calculs de
     * parenté). $parents : ['father_id' => id|null, 'mother_id' => id|null].
     */
    private function ensureNoCycle(string $childId, array $parents): void
    {
        foreach ($parents as $field => $parentId) {
            if (! $parentId) {
                continue;
            }
            if ($parentId === $childId || Person::descendsFrom($parentId, $childId)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    $field => 'Lien impossible : cette personne descend déjà de la fiche (boucle dans l\'arbre).',
                ]);
            }
        }
    }

    public function setParent(Request $request, Person $person)
    {
        $this->authorizeManage($person);

        $validated = $request->validate([
            'parent_id' => ['required', new VisiblePerson],
            'parent_type' => 'required|in:father,mother',
        ]);

        $parent = Person::findOrFail($validated['parent_id']);
        $this->authorizeManage($parent);

        if ($validated['parent_id'] === $person->id) {
            return response()->json(['message' => 'Une personne ne peut pas etre son propre parent'], 422);
        }
        $this->ensureNoCycle($person->id, [$validated['parent_type'].'_id' => $parent->id]);

        $person->update([
            $validated['parent_type'].'_id' => $validated['parent_id'],
        ]);

        return response()->json([
            'message' => 'Parent defini',
            'person' => $person->fresh(),
        ]);
    }

    public function removeParent(Request $request, Person $person)
    {
        $this->authorizeManage($person);

        $validated = $request->validate([
            'parent_type' => 'required|in:father,mother',
        ]);

        $person->update([
            $validated['parent_type'].'_id' => null,
        ]);

        return response()->json(['message' => 'Parent retire']);
    }

    public function addSpouse(Request $request, Person $person)
    {
        $this->authorizeManage($person);

        $validated = $request->validate([
            'spouse_id' => ['required', new VisiblePerson],
            'type' => 'nullable|in:spouse,partner',
            'start_date' => 'nullable|date',
            'start_place' => 'nullable|string|max:255',
        ]);

        $spouse = Person::findOrFail($validated['spouse_id']);
        $this->authorizeManage($spouse);

        if ($validated['spouse_id'] === $person->id) {
            return response()->json(['message' => 'Une personne ne peut pas etre son propre conjoint'], 422);
        }

        $ids = [$person->id, $validated['spouse_id']];
        sort($ids);

        PersonRelationship::firstOrCreate(
            ['person1_id' => $ids[0], 'person2_id' => $ids[1], 'type' => $validated['type'] ?? 'spouse'],
            [
                'start_date' => $validated['start_date'] ?? null,
                'start_place' => $validated['start_place'] ?? null,
            ]
        );

        return response()->json(['message' => 'Relation ajoutee']);
    }

    public function removeSpouse(Request $request, Person $person)
    {
        $this->authorizeManage($person);

        $validated = $request->validate([
            'spouse_id' => ['required', new VisiblePerson],
        ]);

        $ids = [$person->id, $validated['spouse_id']];
        sort($ids);

        // Modèle par modèle : déclenche l'historique (PersonRelationshipObserver).
        PersonRelationship::where('person1_id', $ids[0])
            ->where('person2_id', $ids[1])
            ->get()
            ->each->delete();

        return response()->json(['message' => 'Relation supprimee']);
    }

    /**
     * Ajoute un enfant à une personne. Le parent occupe le slot déduit de son
     * sexe (M → père, F/inconnu → mère/père) ; l'autre parent (conjoint choisi)
     * remplit le slot complémentaire. L'enfant peut être une personne existante.
     */
    public function addChild(Request $request, Person $person)
    {
        $this->authorizeManage($person);

        $validated = $request->validate([
            'child_id' => ['required', new VisiblePerson],
            'other_parent_id' => ['nullable', new VisiblePerson],
            'parent_type' => 'nullable|in:father,mother',
        ]);

        $child = Person::findOrFail($validated['child_id']);
        $this->authorizeManage($child);

        if ($child->id === $person->id) {
            return response()->json(['message' => 'Une personne ne peut pas être son propre enfant'], 422);
        }

        // Slot du parent courant : déduit du sexe, surchargé par parent_type.
        $slot = $validated['parent_type'] ?? ($person->gender === 'F' ? 'mother' : 'father');
        $otherSlot = $slot === 'father' ? 'mother' : 'father';

        $update = [$slot . '_id' => $person->id];

        if (! empty($validated['other_parent_id'])) {
            if ($validated['other_parent_id'] === $child->id) {
                return response()->json(['message' => 'L\'autre parent ne peut pas être l\'enfant'], 422);
            }
            $update[$otherSlot . '_id'] = $validated['other_parent_id'];
        }
        $this->ensureNoCycle($child->id, $update);

        $child->update($update);

        return response()->json([
            'message' => 'Enfant ajouté',
            'child' => $child->fresh(),
        ]);
    }

    /**
     * Peut éditer cette fiche et ses liens : tout membre de la famille
     * (hors invités et tablettes), chaque modification étant journalisée.
     */
    private function canManage(Person $person): bool
    {
        return (bool) auth()->user()?->canEditFamilyTree();
    }

    /**
     * « C'est moi » : on peut toujours se délier de sa propre fiche ; se lier
     * à une fiche demande d'en être le créateur (ou admin) et qu'elle ne soit
     * pas déjà celle d'un autre compte.
     */
    private function canSetSelf(Person $person): bool
    {
        $user = auth()->user();
        if (! $user || $user->isGuest() || $user->simple_mode) {
            return false;
        }
        if ($user->person_id === $person->id) {
            return true;
        }

        return $this->isOwnerOrAdmin($person)
            && ! User::where('person_id', $person->id)->where('id', '!=', $user->id)->exists();
    }

    /**
     * Droits « forts » (suppression, adresse) : le créateur de la fiche ou un
     * admin.
     */
    private function isOwnerOrAdmin(Person $person): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $person->user_id === $user->id);
    }

    private function authorizeManage(Person $person): void
    {
        abort_unless($this->canManage($person), 403);
    }

    /**
     * Compte MemoryLane de la personne : en a-t-elle un, une invitation
     * est-elle en cours, le visiteur peut-il l'inviter ?
     */
    private function accountPayload(Person $person): array
    {
        $viewer = auth()->user();
        $account = User::where('person_id', $person->id)->first(['id', 'memorial_at']);
        $hasAccount = $account !== null;
        $pending = $hasAccount ? null : Invitation::open()
            ->where('person_id', $person->id)
            ->with('inviter:id,name')
            ->latest()
            ->first();

        return [
            'hasAccount' => $hasAccount,
            // Compte conservé « en mémoire » (membre décédé).
            'memorial' => (bool) $account?->memorial_at,
            'pendingInvitation' => $pending ? [
                'sent_at' => $pending->sent_at?->toIso8601String(),
                'inviter' => $pending->inviter?->name,
            ] : null,
            'canInvite' => ! $hasAccount && ! $pending
                && $viewer?->canEditFamilyTree()
                && app(InvitationService::class)->personIneligibility($person) === null
                && app(InvitationService::class)->canInvite($viewer, $person),
        ];
    }

    /**
     * Peut voir l'adresse de résidence (champ $hidden par défaut) : l'éditeur
     * de la fiche (propriétaire/admin), ou un co-membre de foyer si
     * l'utilisateur lié à cette personne a activé « partager mon adresse avec
     * mon foyer » dans son profil. Une personne sans compte n'a pas d'option :
     * son adresse reste visible du seul éditeur/admin.
     */
    private function canSeeAddress(Person $person): bool
    {
        if ($this->isOwnerOrAdmin($person)) {
            return true;
        }

        $viewer = auth()->user();
        if ($viewer === null) {
            return false;
        }

        $linkedUser = User::where('person_id', $person->id)->first();

        // L'adresse se partage dans la FAMILLE (cercles familiaux), jamais
        // entre amis.
        $familyCircles = fn (User $u) => $u->circles()->where('kind', \App\Models\Household::KIND_FAMILY)->pluck('id')->all();

        return $linkedUser !== null
            && $linkedUser->sharesAddressWithHousehold()
            && array_intersect($familyCircles($linkedUser), $familyCircles($viewer)) !== [];
    }

    /**
     * URL d'avatar : la photo de profil explicite si définie, sinon (fallback)
     * le recadrage du visage tagué via l'endpoint faceAvatar. Nécessite que
     * `matched_faces_count` soit chargé (withCount) pour éviter un lien mort.
     */
    private function resolveAvatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            // Endpoint authentifié (servi à tout compte connecté) : plus de
            // présignée longue dans les pages.
            return route('people.avatarImage', $person);
        }

        if (($person->matched_faces_count ?? 0) > 0) {
            return url("/people/{$person->id}/face-avatar");
        }

        return null;
    }

    /**
     * Redirige vers la vignette de la photo de profil explicite (présignée
     * S3 courte). Servi à tout compte connecté, comme faceAvatar : les fiches
     * et l'arbre sont publics entre comptes (phase 1 visibilité).
     */
    public function avatarImage(Person $person)
    {
        $person->loadMissing('avatar.conversions');
        abort_unless($person->avatar, 404);

        $media = $person->avatar;
        $thumb = $media->conversions->firstWhere('conversion_name', 'small')
            ?? $media->conversions->firstWhere('conversion_name', 'thumbnail');

        return $this->mediaService->cachedFileRedirect($media, $thumb?->file_path);
    }

    /**
     * Contrainte de comptage des visages matchés pour les `loadCount()` sur des
     * instances déjà chargées (le scope Person::withMatchedFacesCount couvre
     * les requêtes, mais un scope ne s'applique pas à loadCount).
     */
    private static function matchedFacesCount(): array
    {
        return Person::matchedFacesCountRelation();
    }

    /**
     * Streame un recadrage carré du visage tagué d'une personne, pour servir
     * d'avatar quand aucune photo de profil n'est définie. Même origine +
     * cache HTTP. 404 si la personne n'a aucun visage matché.
     */
    public function faceAvatar(Person $person)
    {
        // Servi à tout compte connecté (route derrière le middleware auth) :
        // la fiche personne est publique en lecture et l'identification des
        // visages est commune à tous. On aligne donc l'avatar-visage sur
        // l'avatar explicite (jusqu'ici il renvoyait 403 hors créateur → avatars
        // cassés sur les fiches de proches vues depuis un autre compte).
        $face = $person->detectedFaces()
            ->usableForAvatar()
            ->with('media.conversions')
            ->orderByDesc('confidence')
            ->first();

        abort_unless($face && $face->media, 404);

        $blob = $this->faceCrops->cropJpeg($face);
        abort_unless($blob, 404);

        return response($blob, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
