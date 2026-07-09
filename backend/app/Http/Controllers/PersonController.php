<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PersonController extends Controller
{
    protected MediaService $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    public function index(Request $request)
    {
        // Arbre/personnes publics : tous les comptes connectés voient les fiches
        // (lecture). L'écriture reste réservée au propriétaire.
        $people = Person::withCount(array_merge(['media'], self::matchedFacesCount()))
            ->with(['avatar.conversions'])
            ->orderBy('name')
            ->get();

        // Proximité généalogique : distance (BFS) depuis la fiche « moi » de
        // l'utilisateur, sur le graphe parents/enfants/conjoints. Sert au tri.
        $selfPersonId = auth()->user()->person_id;
        $proximity = $this->computeProximity($people, $selfPersonId);

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
     * Calcule, pour chaque personne : sa distance de parenté à la fiche « moi »
     * (BFS sur parents/enfants/conjoints) et son nombre de proches directs.
     *
     * @return array{distance: array<string,int>, degree: array<string,int>}
     */
    private function computeProximity($people, ?string $selfPersonId): array
    {
        $ids = $people->pluck('id')->all();
        $known = array_flip($ids);

        // Graphe d'adjacence non orienté
        $adj = array_fill_keys($ids, []);
        foreach ($people as $p) {
            foreach ([$p->father_id, $p->mother_id] as $parentId) {
                if ($parentId && isset($known[$parentId])) {
                    $adj[$p->id][] = $parentId;
                    $adj[$parentId][] = $p->id;
                }
            }
        }

        foreach (DB::table('person_relationships')
            ->whereIn('person1_id', $ids)
            ->orWhereIn('person2_id', $ids)
            ->get(['person1_id', 'person2_id']) as $rel) {
            if (isset($known[$rel->person1_id], $known[$rel->person2_id])) {
                $adj[$rel->person1_id][] = $rel->person2_id;
                $adj[$rel->person2_id][] = $rel->person1_id;
            }
        }

        $degree = [];
        foreach ($adj as $id => $neighbours) {
            $degree[$id] = count(array_unique($neighbours));
        }

        // BFS depuis « moi »
        $distance = [];
        if ($selfPersonId && isset($known[$selfPersonId])) {
            $distance[$selfPersonId] = 0;
            $queue = [$selfPersonId];
            while ($queue) {
                $current = array_shift($queue);
                foreach ($adj[$current] as $neighbour) {
                    if (! isset($distance[$neighbour])) {
                        $distance[$neighbour] = $distance[$current] + 1;
                        $queue[] = $neighbour;
                    }
                }
            }
        }

        return ['distance' => $distance, 'degree' => $degree];
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

        // Load children
        $children = Person::where('father_id', $person->id)
            ->orWhere('mother_id', $person->id)
            ->with('avatar.conversions')
            ->withCount(self::matchedFacesCount())
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
            ->withCount(self::matchedFacesCount())
            ->get();

        $spouses->transform(function ($spouse) {
            $spouse->avatar_url = $this->resolveAvatarUrl($spouse);
            return $spouse;
        });

        // Médias de la personne visibles PAR LE VISITEUR uniquement (la galerie
        // reste privée : ne pas fuiter les photos privées via la fiche, surtout
        // quand l'arbre sera public).
        $media = $person->media()
            ->accessibleBy(auth()->user())
            ->with(['conversions', 'tags'])
            ->orderBy('taken_at', 'desc')
            ->orderBy('uploaded_at', 'desc')
            ->paginate(24);

        $media->getCollection()->transform(function ($item) {
            $item->url = $this->mediaService->getSignedUrl($item);
            if ($item->conversions) {
                $item->conversions->transform(function ($conv) use ($item) {
                    $conv->url = $this->mediaService->getSignedUrl($item, $conv->file_path);
                    return $conv;
                });
            }
            return $item;
        });

        if ($request->wantsJson()) {
            return response()->json([
                'person' => $person,
                'media' => $media,
                'children' => $children,
                'spouses' => $spouses,
            ]);
        }

        return Inertia::render('People/Show', [
            'person' => $person,
            'media' => $media,
            'father' => $person->father,
            'mother' => $person->mother,
            'children' => $children,
            'spouses' => $spouses,
            'isSelf' => auth()->user()->person_id === $person->id,
        ]);
    }

    public function update(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

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
     * URL d'avatar : la photo de profil explicite si définie, sinon (fallback)
     * le recadrage du visage tagué via l'endpoint faceAvatar. Nécessite que
     * `matched_faces_count` soit chargé (withCount) pour éviter un lien mort.
     */
    private function resolveAvatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            return $this->getAvatarUrl($person->avatar);
        }

        if (($person->matched_faces_count ?? 0) > 0) {
            return url("/people/{$person->id}/face-avatar");
        }

        return null;
    }

    /**
     * Contrainte de comptage des visages matchés (réutilisée en withCount).
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
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $face = $person->detectedFaces()
            ->whereNotNull('bounding_box')
            ->where('status', 'matched')
            ->with('media.conversions')
            ->orderByDesc('confidence')
            ->first();

        abort_unless($face && $face->media, 404);

        $media = $face->media;
        $conversion = $media->conversions->firstWhere('conversion_name', 'medium')
            ?? $media->conversions->firstWhere('conversion_name', 'large');
        $path = $conversion->file_path ?? $media->file_path;

        $disk = config('filesystems.default');
        abort_unless(Storage::disk($disk)->exists($path), 404);

        $box = $face->bounding_box;

        try {
            $img = new \Imagick();
            $img->readImageBlob(Storage::disk($disk)->get($path));

            $w = $img->getImageWidth();
            $h = $img->getImageHeight();

            // Carré centré sur le visage, avec une marge autour (× 1.6).
            $bw = ($box['width'] / 100) * $w;
            $bh = ($box['height'] / 100) * $h;
            $cx = ($box['x'] / 100) * $w + $bw / 2;
            $cy = ($box['y'] / 100) * $h + $bh / 2;
            $side = (int) min(max($bw, $bh) * 1.6, $w, $h);
            $left = (int) max(0, min($cx - $side / 2, $w - $side));
            $top = (int) max(0, min($cy - $side / 2, $h - $side));

            $img->cropImage($side, $side, $left, $top);
            $img->resizeImage(256, 256, \Imagick::FILTER_LANCZOS, 1);
            $img->setImageFormat('jpeg');
            $img->setImageCompressionQuality(85);
            $blob = $img->getImageBlob();
            $img->clear();
        } catch (\Throwable $e) {
            abort(404);
        }

        return response($blob, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function getAvatarUrl(Media $media): string
    {
        if ($media->conversions && $media->conversions->count() > 0) {
            $thumb = $media->conversions->firstWhere('conversion_name', 'small')
                ?? $media->conversions->first();
            if ($thumb) {
                return $this->mediaService->getSignedUrl($media, $thumb->file_path);
            }
        }
        return $this->mediaService->getSignedUrl($media);
    }
}
