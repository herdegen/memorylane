<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\AlbumAccess;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Services\MediaService;
use App\Services\SmartAlbumService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class AlbumController extends Controller
{
    protected MediaService $mediaService;
    protected SmartAlbumService $smartAlbumService;

    public function __construct(MediaService $mediaService, SmartAlbumService $smartAlbumService)
    {
        $this->mediaService = $mediaService;
        $this->smartAlbumService = $smartAlbumService;
    }

    /**
     * Règles de validation communes aux albums (dont les règles intelligentes).
     */
    protected function albumRules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'cover_media_id' => 'nullable|exists:media,id',
            'is_public' => 'boolean',
            'is_smart' => 'boolean',
            'smart_rules' => 'nullable|required_if:is_smart,true|array',
            'smart_rules.person_id' => 'nullable|uuid|exists:people,id',
            'smart_rules.tag_id' => 'nullable|integer|exists:tags,id',
            'smart_rules.year' => 'nullable|integer|min:1800|max:2200',
            'smart_rules.type' => 'nullable|in:photo,video,document',
        ];
    }

    public function index(Request $request)
    {
        // Mes albums + ceux partagés avec moi (public / accès accordé / tagué).
        $albums = Album::accessibleBy(auth()->user())
            ->withCount(['media', 'accesses'])
            ->with(['coverMedia.conversions', 'user:id,name'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $albums->transform(function ($album) {
            $album->is_owner = $album->user_id === auth()->id();
            $cover = $this->coverMediaFor($album);
            if ($cover) {
                $album->cover_url = $this->getCoverUrl($cover);
            }
            return $album;
        });

        if ($request->wantsJson()) {
            return response()->json($albums);
        }

        return Inertia::render('Albums/Index', [
            'albums' => $albums,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->albumRules() + [
            // Création « album à partir d'une sélection » : IDs de médias à
            // attacher directement (galerie ou fin d'upload). Optionnel.
            'media_ids' => 'nullable|array',
            'media_ids.*' => 'exists:media,id',
        ]);

        $mediaIds = $validated['media_ids'] ?? [];
        unset($validated['media_ids']);

        $album = Album::create([
            ...$validated,
            'user_id' => auth()->id(),
            'is_public' => $validated['is_public'] ?? false,
        ]);

        // Un album intelligent se remplit dès sa création
        $this->smartAlbumService->refresh($album);

        // Album manuel créé avec une sélection initiale de médias.
        if (! $album->is_smart && ! empty($mediaIds)) {
            $this->attachMediaToAlbum($album, $mediaIds);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Album cree avec succes',
                'album' => $album,
            ], 201);
        }

        return redirect()->route('albums.show', $album)
            ->with('success', 'Album cree avec succes');
    }

    public function show(Request $request, Album $album)
    {
        // Lecture élargie : propriétaire, album public, accès accordé, ou tagué.
        Gate::authorize('view', $album);

        // Album intelligent : contenu recalculé à l'affichage (rapide à
        // l'échelle familiale, garantit un contenu à jour)
        if ($album->is_smart) {
            $this->smartAlbumService->refresh($album);
        }

        $album->load(['coverMedia.conversions', 'media.conversions', 'media.tags']);
        $album->loadCount('media');

        // Nombre de visages reconnus (matched) par média : le diaporama animé
        // s'en sert pour donner la priorité aux photos où des personnes sont
        // identifiées (pondération, pas de filtrage).
        $album->media->loadCount([
            'detectedFaces as matched_faces_count' => fn ($q) => $q->where('status', 'matched'),
        ]);

        $album->media->transform(function ($media) {
            $media->url = $this->mediaService->getSignedUrl($media);
            if ($media->conversions) {
                $media->conversions->transform(function ($conv) use ($media) {
                    $conv->url = $this->mediaService->getSignedUrl($media, $conv->file_path);
                    return $conv;
                });
            }
            return $media;
        });

        $cover = $this->coverMediaFor($album);
        if ($cover) {
            $album->cover_url = $this->getCoverUrl($cover);
        }

        $album->share_url = $album->getShareUrl();
        $album->is_owner = $album->user_id === auth()->id();

        if ($request->wantsJson()) {
            return response()->json($album);
        }

        return Inertia::render('Albums/Show', [
            'album' => $album,
        ]);
    }

    public function update(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate($this->albumRules());

        $album->update($validated);

        // Les règles ont pu changer : on recalcule le contenu
        $this->smartAlbumService->refresh($album->fresh());

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Album modifie avec succes',
                'album' => $album,
            ]);
        }

        return redirect()->back()->with('success', 'Album modifie avec succes');
    }

    public function destroy(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $album->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Album supprime avec succes',
            ]);
        }

        return redirect()->route('albums.index')
            ->with('success', 'Album supprime avec succes');
    }

    public function addMedia(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'media_ids' => 'required|array',
            'media_ids.*' => 'exists:media,id',
        ]);

        $this->attachMediaToAlbum($album, $validated['media_ids']);

        return response()->json([
            'message' => 'Medias ajoutes a l\'album',
        ]);
    }

    /**
     * Attache une liste de médias à un album en préservant l'ordre : ignore
     * ceux déjà présents et incrémente `album_media.order`. Définit la
     * couverture sur le premier média si l'album n'en a pas encore.
     *
     * @param array<int,string> $mediaIds
     */
    protected function attachMediaToAlbum(Album $album, array $mediaIds): void
    {
        $maxOrder = $album->media()->max('album_media.order') ?? -1;

        foreach ($mediaIds as $index => $mediaId) {
            if (!$album->media()->where('media_id', $mediaId)->exists()) {
                $album->media()->attach($mediaId, ['order' => $maxOrder + $index + 1]);
            }
        }

        if (!$album->cover_media_id && count($mediaIds) > 0) {
            $album->update(['cover_media_id' => $mediaIds[0]]);
        }
    }

    public function removeMedia(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'media_ids' => 'required|array',
            'media_ids.*' => 'exists:media,id',
        ]);

        $album->media()->detach($validated['media_ids']);

        if (in_array($album->cover_media_id, $validated['media_ids'])) {
            $firstMedia = $album->media()->first();
            $album->update(['cover_media_id' => $firstMedia?->id]);
        }

        return response()->json([
            'message' => 'Medias retires de l\'album',
        ]);
    }

    public function reorderMedia(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'media_order' => 'required|array',
            'media_order.*' => 'exists:media,id',
        ]);

        foreach ($validated['media_order'] as $order => $mediaId) {
            $album->media()->updateExistingPivot($mediaId, ['order' => $order]);
        }

        return response()->json([
            'message' => 'Ordre mis a jour',
        ]);
    }

    public function generateShareToken(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $token = $album->generateShareToken();

        return response()->json([
            'message' => 'Lien de partage genere',
            'share_token' => $token,
            'share_url' => $album->getShareUrl(),
        ]);
    }

    public function revokeShareToken(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $album->revokeShareToken();

        return response()->json([
            'message' => 'Lien de partage revoque',
        ]);
    }

    public function showShared(Request $request, string $token)
    {
        $album = Album::where('share_token', $token)
            ->with(['coverMedia.conversions', 'media.conversions', 'user:id,name'])
            ->firstOrFail();

        $album->loadCount('media');

        $album->media->transform(function ($media) {
            $media->url = $this->mediaService->getSignedUrl($media);
            if ($media->conversions) {
                $media->conversions->transform(function ($conv) use ($media) {
                    $conv->url = $this->mediaService->getSignedUrl($media, $conv->file_path);
                    return $conv;
                });
            }
            return $media;
        });

        $cover = $this->coverMediaFor($album);
        if ($cover) {
            $album->cover_url = $this->getCoverUrl($cover);
        }

        return Inertia::render('Albums/Shared', [
            'album' => $album,
        ]);
    }

    /**
     * Média à utiliser comme couverture : la couverture explicite si définie,
     * sinon (fallback) la première photo de l'album. Garantit qu'un album non
     * vide a toujours une vignette, même s'il a été rempli avant l'ajout de la
     * logique de couverture automatique.
     */
    private function coverMediaFor(Album $album): ?Media
    {
        if ($album->coverMedia) {
            return $album->coverMedia;
        }

        return $album->media()->with('conversions')->first();
    }

    private function getCoverUrl(Media $media): string
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

    /**
     * Définit la photo de couverture de l'album (média devant appartenir à l'album).
     */
    public function setCover(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'media_id' => ['required', 'uuid', 'exists:media,id'],
        ]);

        abort_unless(
            $album->media()->where('media.id', $validated['media_id'])->exists(),
            422,
            'Ce média ne fait pas partie de l\'album.'
        );

        $album->update(['cover_media_id' => $validated['media_id']]);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Photo de couverture définie.']);
        }

        return redirect()->back()->with('success', 'Photo de couverture définie.');
    }

    /**
     * Applique une localisation (lat/lng) à TOUS les médias de l'album.
     * Utile car l'API Google Photos Picker retire souvent le GPS des originaux.
     */
    public function geolocate(Request $request, Album $album)
    {
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $mediaIds = $album->media()->pluck('media.id');

        foreach ($mediaIds as $mediaId) {
            \App\Models\MediaMetadata::updateOrCreate(
                ['media_id' => $mediaId],
                ['latitude' => $validated['latitude'], 'longitude' => $validated['longitude']],
            );
        }

        $count = $mediaIds->count();

        if ($request->wantsJson()) {
            return response()->json(['message' => "Localisation appliquée à {$count} média(s).", 'count' => $count]);
        }

        return redirect()->back()->with('success', "Localisation appliquée à {$count} média(s).");
    }

    /**
     * Liste des comptes ayant accès à l'album, avec l'origine de l'accès.
     */
    public function accessList(Album $album)
    {
        Gate::authorize('view', $album);

        $entries = [];
        $owner = $album->user()->first();
        if ($owner) {
            $entries[] = $this->accessEntry($owner, 'owner');
        }

        foreach ($album->accesses()->with(['user', 'granter'])->get() as $access) {
            if ($access->user) {
                $entries[] = $this->accessEntry($access->user, 'granted', $access->granter?->name);
            }
        }

        // Personnes taguées AVEC un compte lié.
        $listedIds = array_column($entries, 'user_id');
        $taggedPersonIds = Person::whereHas('media', fn ($m) => $m->whereHas('albums', fn ($a) => $a->whereKey($album->id)))->pluck('id');
        $taggedUsers = User::whereIn('person_id', $taggedPersonIds)
            ->whereNotIn('id', $listedIds)
            ->get();
        foreach ($taggedUsers as $u) {
            $entries[] = $this->accessEntry($u, 'tagged');
        }

        return response()->json($entries);
    }

    private function accessEntry(User $user, string $origin, ?string $grantedBy = null): array
    {
        return [
            'user_id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'origin' => $origin, // owner | granted | tagged
            'granted_by' => $grantedBy,
        ];
    }

    /**
     * Comptes candidats pour accorder un accès (recherche nom/email).
     */
    public function grantCandidates(Request $request, Album $album)
    {
        Gate::authorize('grantAccess', $album);

        $q = trim((string) $request->query('q', ''));
        if (strlen($q) < 2) {
            return response()->json([]);
        }

        $excluded = $album->accesses()->pluck('user_id')->push($album->user_id)->all();

        $users = User::whereNotIn('id', $excluded)
            ->where(fn ($w) => $w->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%"))
            ->orderBy('name')
            ->limit(8)
            ->get(['id', 'name', 'email']);

        return response()->json($users);
    }

    /**
     * Accorder un accès à un compte (délégation possible, cf. AlbumPolicy).
     */
    public function grantAccess(Request $request, Album $album)
    {
        Gate::authorize('grantAccess', $album);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        if ($validated['user_id'] === $album->user_id) {
            return response()->json(['message' => 'Le propriétaire a déjà accès.'], 422);
        }

        AlbumAccess::firstOrCreate(
            ['album_id' => $album->id, 'user_id' => $validated['user_id']],
            ['granted_by' => auth()->id()],
        );

        return response()->json(['message' => 'Accès accordé.'], 201);
    }

    /**
     * Révoquer un accès accordé (owner : n'importe qui ; sinon ses propres octrois).
     */
    public function revokeAccess(Request $request, Album $album)
    {
        $validated = $request->validate([
            'user_id' => ['required', 'uuid'],
        ]);

        $access = $album->accesses()->where('user_id', $validated['user_id'])->first();
        abort_unless($access, 404, 'Aucun accès accordé à révoquer pour ce compte.');

        Gate::authorize('revokeAccess', [$album, $access]);

        $access->delete();

        return response()->json(['message' => 'Accès révoqué.']);
    }
}
