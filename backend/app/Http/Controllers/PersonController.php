<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Media;
use App\Models\User;
use App\Services\GenealogyService;
use App\Services\MediaService;
use App\Services\Vision\AvatarFacePositionService;
use App\Services\Vision\FaceCropService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $people = Person::withCount('media')
            ->withMatchedFacesCount()
            ->with(['avatar.conversions'])
            ->orderBy('name')
            ->get();

        // Proximité généalogique : distance (BFS) depuis la fiche « moi » de
        // l'utilisateur, sur le graphe parents/enfants/conjoints. Sert au tri.
        $selfPersonId = auth()->user()->person_id;
        $proximity = $this->genealogy->proximity($selfPersonId);

        $people->transform(function ($person) use ($proximity) {
            $person->avatar_url = $this->resolveAvatarUrl($person);
            $person->proximity = $proximity['distance'][$person->id] ?? null;
            $person->relatives_count = $proximity['degree'][$person->id] ?? 0;
            return $person;
        });

        if ($request->wantsJson()) {
            return response()->json($people);
        }

        return Inertia::render('People/Index', [
            'people' => $people,
            'selfPersonId' => $selfPersonId,
        ]);
    }

    /**
     * Désigne la personne comme étant l'utilisateur connecté (« c'est moi »),
     * point de référence du tri par proximité.
     */
    public function setSelf(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

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
            'father_id' => 'nullable|exists:people,id',
            'mother_id' => 'nullable|exists:people,id',
        ];
    }

    public function store(Request $request)
    {
        $validated = $this->normalizeNameFields($request->validate($this->personRules()));

        $person = Person::create([
            ...$validated,
            'user_id' => auth()->id(),
        ]);

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
        $father = $this->hydrateAvatar($person->father);
        $mother = $this->hydrateAvatar($person->mother);

        // Frères et sœurs : partagent père OU mère, en excluant la personne.
        $siblings = collect();
        if ($person->father_id || $person->mother_id) {
            $siblings = Person::where('id', '!=', $person->id)
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
        $children = Person::where('father_id', $person->id)
            ->orWhere('mother_id', $person->id)
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

        $spouses = Person::whereIn('id', $spouseIds)
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
            'canManage' => $this->canManage($person),
        ]);
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

        $people = Person::whereIn('id', $path['ids'])
            ->with('avatar.conversions')
            ->withMatchedFacesCount()
            ->get()
            ->keyBy('id');

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
            'relation_label' => $this->kinshipLabel($path['edges'], $person->gender),
            'path' => $orderedPath,
            'edge_labels' => $stepLabels,
            'apex_index' => $apexIndex,
        ]);
    }

    /**
     * Nom français du lien pour les motifs courants (genré sur la cible),
     * « par alliance » quand un unique pas conjoint encadre un motif connu.
     * Null pour les chemins exotiques (le front affichera « lien en N pas »).
     */
    private function kinshipLabel(array $edges, ?string $gender): ?string
    {
        $f = $gender === 'F';
        $direct = [
            'parent' => $f ? 'votre mère' : 'votre père',
            'parent.parent' => $f ? 'votre grand-mère' : 'votre grand-père',
            'parent.parent.parent' => $f ? 'votre arrière-grand-mère' : 'votre arrière-grand-père',
            'child' => $f ? 'votre fille' : 'votre fils',
            'child.child' => $f ? 'votre petite-fille' : 'votre petit-fils',
            'child.child.child' => $f ? 'votre arrière-petite-fille' : 'votre arrière-petit-fils',
            'parent.child' => $f ? 'votre sœur' : 'votre frère',
            'parent.child.child' => $f ? 'votre nièce' : 'votre neveu',
            'parent.parent.child' => $f ? 'votre tante' : 'votre oncle',
            'parent.parent.child.child' => $f ? 'votre cousine germaine' : 'votre cousin germain',
            'spouse' => $f ? 'votre conjointe' : 'votre conjoint',
            'spouse.parent' => $f ? 'votre belle-mère' : 'votre beau-père',
            'parent.child.spouse' => $f ? 'votre belle-sœur' : 'votre beau-frère',
            'spouse.parent.child' => $f ? 'votre belle-sœur' : 'votre beau-frère',
            'child.spouse' => $f ? 'votre belle-fille' : 'votre gendre',
        ];

        $key = implode('.', $edges);
        if (isset($direct[$key])) {
            return $direct[$key];
        }

        // Un unique pas « conjoint » en tête ou en queue : motif connu par alliance.
        if (count($edges) > 1 && ! in_array('spouse', array_slice($edges, 1, -1), true)) {
            foreach ([['start', array_slice($edges, 1)], ['end', array_slice($edges, 0, -1)]] as [$where, $rest]) {
                $edge = $where === 'start' ? $edges[0] : end($edges);
                if ($edge === 'spouse' && isset($direct[implode('.', $rest)])) {
                    return $direct[implode('.', $rest)] . ' par alliance';
                }
            }
        }

        return null;
    }

    /**
     * Frise de vie : fusion d'événements auto-déduits (naissance, mariages,
     * naissances des enfants, décès), des moments libres (life_events) et de
     * toutes les photos datées de la personne, triés par date croissante.
     */
    public function timeline(Person $person)
    {
        $items = [];

        if ($person->birth_date) {
            $items[] = $this->autoEvent('birth', $person->birth_date, 'Naissance', $person->birth_place);
        }
        if ($person->death_date) {
            $items[] = $this->autoEvent('death', $person->death_date, 'Décès', $person->death_place);
        }

        // Mariages / unions (dates portées par le pivot person_relationships)
        foreach ($person->spouses as $spouse) {
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
        $children = Person::where('father_id', $person->id)
            ->orWhere('mother_id', $person->id)
            ->whereNotNull('birth_date')
            ->get();
        foreach ($children as $child) {
            $items[] = array_merge(
                $this->autoEvent('child', $child->birth_date, 'Naissance de ' . $child->name, $child->birth_place),
                ['related' => $this->relatedPayload($child)]
            );
        }

        // Moments libres
        foreach ($person->lifeEvents()->with(['media.conversions', 'album'])->get() as $ev) {
            $items[] = [
                'date' => optional($ev->event_date)->format('Y-m-d'),
                'end_date' => optional($ev->end_date)->format('Y-m-d'),
                'kind' => $ev->type,
                'title' => $ev->title,
                'description' => $ev->description,
                'place' => $ev->place,
                // Lieu géolocalisé (animation carte du diaporama à venir).
                'latitude' => $ev->latitude,
                'longitude' => $ev->longitude,
                'media' => $ev->media ? $this->mediaPayload($ev->media) : null,
                // Album lié, seulement s'il est accessible au visiteur.
                'album' => $ev->album && $ev->album->isAccessibleBy(auth()->user())
                    ? ['id' => $ev->album->id, 'name' => $ev->album->name]
                    : null,
                'life_event_id' => $ev->id,
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
            $accessibleAlbumIds = Album::accessibleBy(auth()->user())->pluck('id');
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
        $geocoder = app(\App\Services\GeocodeService::class);
        $placePhotos = app(\App\Services\PlacePhotoService::class);
        $items = $items->map(function ($item) use ($geocoder, $placePhotos) {
            if (in_array($item['kind'] ?? '', ['photo', 'child'], true)) {
                return $item;
            }
            // Lieux GEDCOM du type « Croix, , , , » : virgules vides nettoyées.
            if (! empty($item['place'])) {
                $item['place'] = trim(preg_replace('/(\s*,\s*)+/', ', ', $item['place']), " \t,");
            }
            if (empty($item['latitude']) && ! empty($item['place'])) {
                if ($coords = $geocoder->coordinatesFor($item['place'])) {
                    $item['latitude'] = $coords['latitude'];
                    $item['longitude'] = $coords['longitude'];
                }
            }
            if (! empty($item['latitude']) && ! empty($item['longitude'])) {
                $item['place_photo_url'] = $placePhotos->photoFor((float) $item['latitude'], (float) $item['longitude']);
            }

            return $item;
        });

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
        $person->loadMissing('avatar.conversions');
        $person->loadCount(self::matchedFacesCount());

        return [
            'id' => $person->id,
            'name' => $person->name,
            'avatar_url' => $this->resolveAvatarUrl($person),
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
            'taken_at' => optional($media->taken_at)->toIso8601String(),
            'url' => $this->mediaService->fileUrl($media),
            'thumbnail_url' => $thumb ? $this->mediaService->fileUrl($media, $thumb->conversion_name) : null,
            'medium_url' => $medium ? $this->mediaService->fileUrl($media, $medium->conversion_name) : null,
        ];
    }

    public function update(Request $request, Person $person)
    {
        // Aligné sur les autres actions (et sur le bouton « Modifier » de la
        // fiche, déjà affiché aux admins) : propriétaire OU admin.
        $this->authorizeManage($person);

        $validated = $this->normalizeNameFields($request->validate($this->personRules()));

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
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

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
            'person_id' => 'required|exists:people,id',
        ]);

        $media = Media::findOrFail($validated['media_id']);
        $person = Person::findOrFail($validated['person_id']);

        if ($media->user_id !== auth()->id() || $person->user_id !== auth()->id()) {
            abort(403);
        }

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
            'person_id' => 'required|exists:people,id',
        ]);

        $media = Media::findOrFail($validated['media_id']);
        $person = Person::findOrFail($validated['person_id']);

        if ($media->user_id !== auth()->id() || $person->user_id !== auth()->id()) {
            abort(403);
        }

        $media->people()->detach($person->id);

        return response()->json([
            'message' => 'Personne retiree du media',
        ]);
    }

    public function setParent(Request $request, Person $person)
    {
        $this->authorizeManage($person);

        $validated = $request->validate([
            'parent_id' => 'required|exists:people,id',
            'parent_type' => 'required|in:father,mother',
        ]);

        $parent = Person::findOrFail($validated['parent_id']);
        $this->authorizeManage($parent);

        if ($validated['parent_id'] === $person->id) {
            return response()->json(['message' => 'Une personne ne peut pas etre son propre parent'], 422);
        }

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
            'spouse_id' => 'required|exists:people,id',
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
            'spouse_id' => 'required|exists:people,id',
        ]);

        $ids = [$person->id, $validated['spouse_id']];
        sort($ids);

        PersonRelationship::where('person1_id', $ids[0])
            ->where('person2_id', $ids[1])
            ->delete();

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
            'child_id' => 'required|exists:people,id',
            'other_parent_id' => 'nullable|exists:people,id',
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

        $child->update($update);

        return response()->json([
            'message' => 'Enfant ajouté',
            'child' => $child->fresh(),
        ]);
    }

    /**
     * Peut gérer/éditer cette fiche : un admin, ou le créateur (propriétaire).
     * (Le cas « la personne elle-même via un compte connecté » reste à modéliser,
     * cf. issue #20.)
     */
    private function canManage(Person $person): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->isAdmin() || $person->user_id === $user->id);
    }

    private function authorizeManage(Person $person): void
    {
        abort_unless($this->canManage($person), 403);
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
        if ($this->canManage($person)) {
            return true;
        }

        $viewer = auth()->user();
        if ($viewer === null) {
            return false;
        }

        $linkedUser = User::where('person_id', $person->id)->first();

        return $linkedUser !== null
            && $linkedUser->sharesAddressWithHousehold()
            && $linkedUser->sharesHouseholdWith($viewer);
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

        return redirect()
            ->away($this->mediaService->getSignedUrl($media, $thumb?->file_path, 10))
            ->header('Cache-Control', 'private, max-age=300');
    }

    /**
     * Contrainte de comptage des visages matchés pour les `loadCount()` sur des
     * instances déjà chargées (le scope Person::withMatchedFacesCount couvre
     * les requêtes, mais un scope ne s'applique pas à loadCount).
     */
    private static function matchedFacesCount(): array
    {
        return ['detectedFaces as matched_faces_count' => function ($q) {
            $q->where('status', 'matched')->whereNotNull('bounding_box');
        }];
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
            ->whereNotNull('bounding_box')
            ->where('status', 'matched')
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
