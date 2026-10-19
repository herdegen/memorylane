<?php

namespace App\Http\Controllers;

use App\Rules\VisiblePerson;
use App\Jobs\GenerateHeroClip;
use App\Models\Album;
use App\Services\HeroClipService;
use App\Models\Household;
use App\Models\AlbumAccess;
use App\Models\Media;
use App\Models\Person;
use App\Models\User;
use App\Notifications\Push\AlbumAccessGranted;
use App\Notifications\Push\PushNotification;
use App\Services\PushNotifier;
use App\Services\MediaService;
use App\Services\SmartAlbumService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'cover_media_id' => 'nullable|uuid|exists:media,id',
            'is_smart' => 'boolean',
            'smart_rules' => 'nullable|required_if:is_smart,true|array',
            'smart_rules.person_id' => ['nullable', 'uuid', new VisiblePerson],
            'smart_rules.tag_id' => 'nullable|integer|exists:tags,id',
            'smart_rules.year' => 'nullable|integer|min:1800|max:2200',
            'smart_rules.type' => 'nullable|in:photo,video,document',
        ];
    }

    public function index(Request $request)
    {
        // Mes albums + ceux partagés avec moi (accès accordé / cercle / tagué).
        $albums = Album::accessibleBy(auth()->user())
            ->withCount(['media', 'accesses', 'households'])
            ->with(['coverMedia.conversions', 'user:id,name', 'households:id'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $inContext = $this->albumIdsInContext($request->user(), $albums);

        $albums->transform(function ($album) use ($inContext) {
            $album->is_owner = $album->user_id === auth()->id();
            // Cercles de l'album : l'uploader ne propose que ceux du cercle courant.
            $album->household_ids = $album->households->pluck('id')->values();
            $album->unsetRelation('households');
            // Rangement de la page : albums du cercle courant d'abord, les
            // autres à part (grisés, avec « Partager avec ce cercle »).
            $album->in_context = isset($inContext[$album->id]);
            $cover = $this->coverMediaFor($album);
            if ($cover) {
                $album->cover_url = $this->getCoverUrl($cover);
            }
            return $album;
        });

        if ($request->wantsJson()) {
            return response()->json($albums);
        }

        $this->attachDiscovery($albums);

        return Inertia::render('Albums/Index', [
            'albums' => $albums,
            'discovery' => $this->discoverySections($request->user(), $albums),
        ]);
    }

    /** Photos tirées au sort par album pour les couvertures vivantes. */
    private const PREVIEWS_PER_ALBUM = 6;

    /**
     * Couvertures vivantes : 6 photos par album, tirées au sort à chaque visite
     * (« à la une » et photos avec des proches identifiés d'abord), plus la
     * période et la date du dernier ajout (rangées de la page).
     */
    private function attachDiscovery($albums): void
    {
        $ids = $albums->pluck('id')->all();
        if ($ids === []) {
            return;
        }

        $picked = collect(DB::select(
            'select album_id, media_id from (
                select am.album_id, am.media_id, row_number() over (
                    partition by am.album_id
                    order by (am.pin_order is not null) desc,
                             exists (select 1 from detected_faces df where df.media_id = m.id and df.person_id is not null) desc,
                             random()
                ) as rn
                from album_media am join media m on m.id = am.media_id
                where am.album_id in ('.implode(',', array_fill(0, count($ids), '?')).')
                  and m.deleted_at is null and m.type = \'photo\'
            ) t where rn <= ?',
            [...$ids, self::PREVIEWS_PER_ALBUM]
        ));
        $media = Media::with('conversions')->whereIn('id', $picked->pluck('media_id')->unique())->get()->keyBy('id');

        $stats = DB::table('album_media as am')->join('media as m', 'm.id', '=', 'am.media_id')
            ->whereIn('am.album_id', $ids)->whereNull('m.deleted_at')
            ->groupBy('am.album_id')
            ->selectRaw('am.album_id, min(m.taken_at) as first_at, max(m.taken_at) as last_at, max(am.created_at) as added_at,
                sum(case when extract(month from m.taken_at) = ? and extract(year from m.taken_at) < ? then 1 else 0 end) as month_photos,
                min(case when extract(month from m.taken_at) = ? and extract(year from m.taken_at) < ? then extract(year from m.taken_at) end) as month_year',
                [now()->month, now()->year, now()->month, now()->year])
            ->get()->keyBy('album_id');

        $albums->each(function (Album $album) use ($picked, $media, $stats) {
            $urls = $picked->where('album_id', $album->id)->pluck('media_id')->shuffle()
                ->map(fn ($id) => isset($media[$id]) ? [
                    'small' => $this->mediaService->thumbnailUrl($media[$id]),
                    'medium' => $this->mediaService->thumbnailUrl($media[$id], ['medium', 'small', 'thumbnail']),
                ] : null)->filter()->values();
            $album->previews = $urls;
            $st = $stats[$album->id] ?? null;
            $album->first_at = $st?->first_at ? substr($st->first_at, 0, 10) : null;
            $album->last_at = $st?->last_at ? substr($st->last_at, 0, 10) : null;
            $album->added_at = $st?->added_at;
            $album->month_photos = (int) ($st->month_photos ?? 0);
            $album->month_year = $st?->month_year ? (int) $st->month_year : null;
        });
    }

    /**
     * Ce qui rend la page différente à chaque visite : l'album à l'honneur
     * (anniversaire ce mois-ci, sinon récemment enrichi, sinon au hasard) et
     * des rangées thématiques — sur les albums du cercle courant.
     */
    private function discoverySections(User $user, $albums): array
    {
        $here = $albums->filter(fn ($a) => $a->in_context && ($a->media_count ?? 0) > 0 && count($a->previews ?? []) > 0)->values();
        if ($here->isEmpty()) {
            return ['featured' => null, 'rows' => [], 'decades' => []];
        }

        $thisMonth = $here->filter(fn ($a) => $a->month_photos > 0)->sortByDesc('month_photos')->values();
        $recent = $here->filter(fn ($a) => $a->added_at && now()->diffInDays($a->added_at, true) <= 45)->sortByDesc('added_at')->values();

        if ($thisMonth->isNotEmpty()) {
            $f = $thisMonth->take(3)->random();
            $years = now()->year - $f->month_year;
            $featured = ['id' => $f->id, 'reason' => $years === 1 ? 'Il y a un an ce mois-ci' : "Il y a {$years} ans ce mois-ci"];
        } elseif ($recent->isNotEmpty()) {
            $featured = ['id' => $recent->first()->id, 'reason' => 'Récemment enrichi'];
        } else {
            $featured = ['id' => $here->random()->id, 'reason' => 'À redécouvrir'];
        }

        $rows = [];
        if ($thisMonth->count() >= 1) {
            $rows[] = ['key' => 'month', 'title' => 'Ce mois-ci, les années passées', 'ids' => $thisMonth->pluck('id')->all()];
        }
        if ($recent->count() >= 2) {
            $rows[] = ['key' => 'recent', 'title' => 'Récemment enrichis', 'ids' => $recent->take(12)->pluck('id')->all()];
        }

        // Les proches les plus présents dans ces albums : une rangée chacun.
        $ids = $here->pluck('id')->all();
        $top = DB::table('media_person as mp')->join('album_media as am', 'am.media_id', '=', 'mp.media_id')
            ->whereIn('am.album_id', $ids)
            ->whereIn('mp.person_id', Person::inContext($user)->select('people.id'))
            ->when($user->person_id, fn ($q) => $q->where('mp.person_id', '!=', $user->person_id))
            ->groupBy('mp.person_id')->selectRaw('mp.person_id, count(distinct am.album_id) as albums')
            ->having(DB::raw('count(distinct am.album_id)'), '>=', 2)
            ->orderByDesc('albums')->limit(2)->pluck('person_id');
        foreach (Person::whereIn('id', $top)->get(['id', 'name', 'first_name']) as $person) {
            $withIds = DB::table('media_person as mp')->join('album_media as am', 'am.media_id', '=', 'mp.media_id')
                ->where('mp.person_id', $person->id)->whereIn('am.album_id', $ids)
                ->groupBy('am.album_id')->orderByRaw('count(*) desc')->pluck('am.album_id')->all();
            $rows[] = ['key' => 'person-'.$person->id, 'title' => 'Avec '.($person->first_name ?: explode(' ', $person->name)[0]), 'ids' => $withIds];
        }

        // Décennies (d'après la première photo datée de chaque album).
        $decades = $here->filter(fn ($a) => $a->first_at)
            ->groupBy(fn ($a) => intdiv((int) substr($a->first_at, 0, 4), 10) * 10)
            ->map(fn ($g, $decade) => ['decade' => (int) $decade, 'ids' => $g->pluck('id')->all()])
            ->sortKeys()->values()->all();

        // Ordre des rangées mélangé (sauf « ce mois-ci » en tête) : la page
        // bouge. Une rangée doit apporter au moins 2 albums pas encore montrés
        // (sinon, avec peu d'albums, toutes les rangées se ressemblent) ; 4 au plus.
        $first = array_values(array_filter($rows, fn ($r) => $r['key'] === 'month'));
        $others = collect($rows)->reject(fn ($r) => $r['key'] === 'month')->shuffle()->values()->all();
        $shown = [$featured['id'] => true];
        $kept = [];
        foreach ([...$first, ...$others] as $row) {
            $new = array_filter($row['ids'], fn ($id) => ! isset($shown[$id]));
            if (count($row['ids']) < 2 || count($new) < 2 || count($kept) >= 4) {
                continue;
            }
            foreach ($row['ids'] as $id) {
                $shown[$id] = true;
            }
            $kept[] = $row;
        }

        return ['featured' => $featured, 'rows' => $kept, 'decades' => $decades];
    }

    /**
     * Albums qui appartiennent au cercle courant.
     * - Cercle d'amis : partagés avec lui, ou montrant un ami du cercle.
     * - Famille : partagés avec un cercle familial, ou sans cercle du tout
     *   (privés) ; un album partagé seulement avec des cercles d'amis est
     *   « ailleurs ».
     *
     * @return array<string,true>
     */
    private function albumIdsInContext(User $user, $albums): array
    {
        $circle = $user->currentCircle();
        $ids = [];

        if ($circle?->isFriends()) {
            $friendIds = $circle->people()
                ->when($user->person_id, fn ($q) => $q->where('people.id', '!=', $user->person_id))
                ->pluck('people.id');
            $withFriends = DB::table('album_media as am')
                ->join('media_person as mp', 'mp.media_id', '=', 'am.media_id')
                ->whereIn('am.album_id', $albums->pluck('id'))
                ->whereIn('mp.person_id', $friendIds)
                ->distinct()
                ->pluck('am.album_id')
                ->flip();
            foreach ($albums as $album) {
                if ($album->households->contains('id', $circle->id) || isset($withFriends[$album->id])) {
                    $ids[$album->id] = true;
                }
            }

            return $ids;
        }

        $familyCircles = Household::where('kind', Household::KIND_FAMILY)->pluck('id')->flip();
        foreach ($albums as $album) {
            $circles = $album->households->pluck('id');
            if ($circles->isEmpty() || $circles->contains(fn ($id) => isset($familyCircles[$id]))) {
                $ids[$album->id] = true;
            }
        }

        return $ids;
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->albumRules() + [
            // Création « album à partir d'une sélection » : IDs de médias à
            // attacher directement (galerie ou fin d'upload). Optionnel.
            'media_ids' => 'nullable|array|max:5000',
            'media_ids.*' => 'uuid',
        ]);

        $mediaIds = $validated['media_ids'] ?? [];
        unset($validated['media_ids']);

        if ($mediaIds !== []) {
            $this->ensureMediaAccessible($mediaIds);
        }
        // Couverture : uniquement une photo que l'on peut voir (sinon ses
        // métadonnées seraient servies à tous les lecteurs de l'album).
        if (! empty($validated['cover_media_id'])) {
            $this->ensureMediaAccessible([$validated['cover_media_id']]);
        }

        $album = Album::create([
            ...$validated,
            'user_id' => auth()->id(),
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
        // NB : contrainte volontairement différente de Person::withMatchedFacesCount
        // (modèle Media, et pas d'exigence de bounding_box ici).
        // + visages encore à identifier : bouton « Qui est-ce ? » de la page
        // (téléphone) qui ne montre que ces photos.
        $album->media->loadCount([
            'detectedFaces as matched_faces_count' => fn ($q) => $q->where('status', 'matched'),
        ]);

        // Visages à identifier par photo, tels que le jeu « Qui est-ce ? » les
        // proposera (arrière-plans, inconnus du cercle, mes « passer » écartés).
        $pending = app(\App\Services\QuestService::class)->pendingFacesByMedia($request->user(), $album);
        $album->media->each(fn ($m) => $m->pending_faces_count = $pending[$m->id] ?? 0);

        // « Qui était là ? » : aperçu des participants déclarés (carte sous le
        // héro sur téléphone) ; la liste complète s'édite dans la modale.
        $album->participants_preview = $album->participants()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->get(['people.id', 'people.name'])
            ->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name])
            ->values();

        $this->mediaService->hydrateSignedUrls($album->media);

        $cover = $this->coverMediaFor($album);
        if ($cover) {
            $album->cover_url = $this->getCoverUrl($cover);
        }

        $album->hero = $this->heroFor($album);

        $album->is_owner = $album->user_id === auth()->id();
        // Lien anonyme : son URL n'est donnée qu'au propriétaire.
        $album->share_url = $album->is_owner ? $album->getShareUrl() : null;

        // Position du héro (fond carte) : barycentre des médias géolocalisés.
        $location = \App\Models\MediaMetadata::whereIn('media_id', $album->media->pluck('id'))
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->selectRaw('avg(latitude) as latitude, avg(longitude) as longitude, count(*) as located_count')
            ->first();
        $album->hero_location = ($location && (int) $location->located_count > 0)
            ? [
                'latitude' => (float) $location->latitude,
                'longitude' => (float) $location->longitude,
                'count' => (int) $location->located_count,
            ]
            : null;

        $album->places = $this->placesOf($album);

        if ($request->wantsJson()) {
            return response()->json($album);
        }

        return Inertia::render('Albums/Show', [
            'album' => $album,
        ]);
    }

    public function update(Request $request, Album $album)
    {
        Gate::authorize('update', $album);

        $validated = $request->validate($this->albumRules());
        if (! empty($validated['cover_media_id']) && $validated['cover_media_id'] !== $album->cover_media_id) {
            $this->ensureMediaAccessible([$validated['cover_media_id']]);
        }

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
        Gate::authorize('delete', $album);

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
        Gate::authorize('manageMedia', $album);

        $validated = $request->validate([
            'media_ids' => 'required|array|max:5000',
            'media_ids.*' => 'uuid',
        ]);

        $this->ensureMediaAccessible($validated['media_ids']);

        $this->attachMediaToAlbum($album, $validated['media_ids']);

        return response()->json([
            'message' => 'Medias ajoutes a l\'album',
        ]);
    }

    /**
     * Vérifie que TOUS les médias demandés sont accessibles à l'appelant.
     * Sans ce garde, attacher l'UUID du média privé d'un tiers à son propre
     * album s'en ouvrirait la lecture (Media::scopeAccessibleBy considère
     * accessibles les médias des albums accessibles). Couvre aussi les IDs
     * inexistants (non accessibles par définition).
     */
    protected function ensureMediaAccessible(array $mediaIds): void
    {
        $ids = array_values(array_unique($mediaIds));

        $accessibleCount = Media::accessibleBy(auth()->user())
            ->whereIn('id', $ids)
            ->count();

        abort_unless($accessibleCount === count($ids), 403, 'Certains médias sélectionnés ne vous sont pas accessibles.');
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

        // 2 requêtes au lieu de 2 par média : les présents en une passe,
        // l'attache groupée en une seule insertion.
        $existing = $album->media()->whereIn('media.id', $mediaIds)->pluck('media.id')->all();

        $order = $maxOrder;
        $toAttach = collect(array_unique($mediaIds))
            ->diff($existing)
            ->mapWithKeys(fn ($mediaId) => [$mediaId => ['order' => ++$order]])
            ->all();

        if ($toAttach !== []) {
            $album->media()->attach($toAttach);
        }

        if (!$album->cover_media_id && count($mediaIds) > 0) {
            $album->update(['cover_media_id' => $mediaIds[0]]);
        }
    }

    public function removeMedia(Request $request, Album $album)
    {
        $validated = $request->validate([
            'media_ids' => 'required|array|max:5000',
            'media_ids.*' => 'uuid',
        ]);

        $userId = auth()->id();
        $ids = $validated['media_ids'];

        // Le propriétaire de l'album peut retirer n'importe quel média. Un
        // contributeur (album partagé) ne peut retirer QUE les médias qu'il a
        // lui-même ajoutés — c.-à-d. dont il est propriétaire.
        if ($album->user_id !== $userId) {
            $ids = Media::whereIn('id', $ids)
                ->where('user_id', $userId)
                ->pluck('id')
                ->all();

            abort_if(empty($ids), 403, 'Vous ne pouvez retirer que les médias que vous avez ajoutés.');
        }

        $album->media()->detach($ids);

        if (in_array($album->cover_media_id, $ids, true)) {
            $firstMedia = $album->media()->first();
            $album->update(['cover_media_id' => $firstMedia?->id]);
        }

        return response()->json([
            'message' => 'Medias retires de l\'album',
            'removed' => count($ids),
        ]);
    }

    public function generateShareToken(Request $request, Album $album)
    {
        Gate::authorize('update', $album);

        $token = $album->generateShareToken();

        return response()->json([
            'message' => 'Lien de partage genere',
            'share_token' => $token,
            'share_url' => $album->getShareUrl(),
        ]);
    }

    public function revokeShareToken(Request $request, Album $album)
    {
        Gate::authorize('update', $album);

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

        // Visiteur anonyme : toutes les URLs passent par la route token.
        $this->mediaService->hydrateSharedUrls($token, $album->media);

        $cover = $this->coverMediaFor($album);
        if ($cover) {
            $thumb = $cover->conversions->firstWhere('conversion_name', 'small')
                ?? $cover->conversions->firstWhere('conversion_name', 'thumbnail');
            $album->cover_url = route('albums.shared.file', array_filter([
                'token' => $token,
                'media' => $cover->id,
                'conversion' => $thumb?->conversion_name,
            ]));
        }

        return Inertia::render('Albums/Shared', [
            'album' => $album,
        ]);
    }

    /**
     * Sert un fichier d'un album partagé par lien secret : le token vaut
     * autorisation, mais uniquement pour les médias DE CET album. 302 vers
     * une présignée S3 courte.
     */
    public function sharedFile(string $token, Media $media, ?string $conversion = null)
    {
        $album = Album::where('share_token', $token)->firstOrFail();

        abort_unless($album->media()->whereKey($media->id)->exists(), 404);

        $conversionPath = null;
        if ($conversion !== null) {
            $conversionModel = $media->conversions()->where('conversion_name', $conversion)->first();
            abort_unless($conversionModel, 404);
            $conversionPath = $conversionModel->file_path;
        }

        return $this->mediaService->cachedFileRedirect($media, $conversionPath);
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
        return $this->mediaService->thumbnailUrl($media);
    }

    /**
     * Étapes d'un album tourné à plusieurs endroits : médias géolocalisés
     * regroupés par lieu (~2 km), dans l'ordre chronologique (date, puis
     * position dans la vidéo d'origine pour des clips). Sert la carte-trajet
     * de l'en-tête et le diaporama qui voyage.
     *
     * @return list<array{index: int, latitude: float, longitude: float, label: string, media_ids: list<string>, cover_url: string}>
     */
    private function placesOf(Album $album): array
    {
        $coords = \App\Models\MediaMetadata::whereIn('media_id', $album->media->pluck('id'))
            ->whereNotNull('latitude')->whereNotNull('longitude')
            ->get(['media_id', 'latitude', 'longitude'])->keyBy('media_id');
        if ($coords->isEmpty()) {
            return [];
        }

        $located = $album->media->filter(fn ($m) => isset($coords[$m->id]))
            ->sortBy(fn ($m) => [$m->taken_at?->timestamp ?? PHP_INT_MAX, $m->clip_start ?? 0, $m->created_at?->timestamp ?? 0])
            ->values();

        $places = [];
        foreach ($located as $media) {
            $lat = (float) $coords[$media->id]->latitude;
            $lng = (float) $coords[$media->id]->longitude;
            $key = collect($places)->search(fn ($p) => $this->kmBetween($p['latitude'], $p['longitude'], $lat, $lng) < 2);
            if ($key === false) {
                $places[] = [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'label' => $media->title ?: pathinfo($media->original_name, PATHINFO_FILENAME),
                    'media_ids' => [$media->id],
                    'cover_url' => $this->mediaService->thumbnailUrl($media),
                ];
            } else {
                $places[$key]['media_ids'][] = $media->id;
            }
        }

        return collect($places)->values()->map(fn ($p, $i) => ['index' => $i + 1, ...$p])->all();
    }

    private function kmBetween(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Fond du titre choisi parmi les médias de l'album (tant qu'il y est) :
     * photo (grande conversion) ou vidéo (extrait en boucle ; en attendant
     * qu'il soit prêt, une image de la vidéo). Null : fond par défaut.
     *
     * @return array{type: string, src: ?string, poster: ?string}|null
     */
    private function heroFor(Album $album): ?array
    {
        $media = $album->hero_media_id ? $album->media->firstWhere('id', $album->hero_media_id) : null;
        if (! $media) {
            return null;
        }
        $has = fn (string $name) => $media->conversions->contains('conversion_name', $name);

        if ($media->type === 'video') {
            return [
                'type' => 'video',
                'src' => $has(HeroClipService::CLIP) ? $this->mediaService->fileUrl($media, HeroClipService::CLIP) : null,
                'poster' => $has(HeroClipService::POSTER) ? $this->mediaService->fileUrl($media, HeroClipService::POSTER) : $this->mediaService->thumbnailUrl($media, ['medium', 'small', 'thumbnail']),
            ];
        }
        $size = collect(['large', 'medium'])->first($has);

        return ['type' => 'photo', 'src' => $this->mediaService->fileUrl($media, $size), 'poster' => null];
    }

    /**
     * Choisit le fond du titre parmi les photos et vidéos de l'album (vidéo :
     * extrait préparé en tâche de fond), ou revient au fond par défaut.
     */
    public function setHero(Request $request, Album $album)
    {
        Gate::authorize('manageMedia', $album);

        $validated = $request->validate([
            'media_id' => ['nullable', 'uuid'],
            'start' => ['nullable', 'numeric', 'min:0'],
        ]);

        if (! ($validated['media_id'] ?? null)) {
            $album->forceFill(['hero_media_id' => null])->save();

            return response()->json(['message' => 'Fond par défaut rétabli.']);
        }

        $media = $album->media()->whereKey($validated['media_id'])->whereIn('type', ['photo', 'video'])->first();
        abort_unless($media, 422, 'Choisissez une photo ou une vidéo de l\'album.');
        $start = (float) ($validated['start'] ?? 0);
        abort_if($media->type === 'video' && $media->duration && $start >= $media->duration, 422, "La vidéo ne dure que {$media->duration} s.");

        $album->forceFill(['hero_media_id' => $media->id])->save();
        if ($media->type === 'video') {
            GenerateHeroClip::dispatch($media, $start);
        }

        return response()->json([
            'message' => $media->type === 'video' ? 'Fond vidéo en préparation (une minute environ).' : 'Fond du titre défini.',
            'preparing' => $media->type === 'video',
        ]);
    }

    /**
     * « À la une » : épingle (dans l'ordre donné, après les épinglés actuels)
     * ou désépingle des médias de l'album ; les épinglés s'affichent en tête.
     */
    public function pin(Request $request, Album $album)
    {
        Gate::authorize('manageMedia', $album);

        $validated = $request->validate([
            'media_ids' => ['required', 'array', 'max:200'],
            'media_ids.*' => ['uuid'],
            'pinned' => ['required', 'boolean'],
        ]);

        DB::transaction(function () use ($album, $validated) {
            $pivot = DB::table('album_media')->where('album_id', $album->id);
            $ids = array_values(array_unique($validated['media_ids']));

            if ($validated['pinned']) {
                $next = (int) (clone $pivot)->max('pin_order') + 1;
                $alreadyPinned = (clone $pivot)->whereNotNull('pin_order')->pluck('media_id')->all();
                foreach (array_diff($ids, $alreadyPinned) as $id) {
                    (clone $pivot)->where('media_id', $id)->update(['pin_order' => $next++]);
                }
            } else {
                (clone $pivot)->whereIn('media_id', $ids)->update(['pin_order' => null]);
            }

            // Numérotation resserrée (0, 1, 2…) dans l'ordre actuel.
            foreach ((clone $pivot)->whereNotNull('pin_order')->orderBy('pin_order')->pluck('media_id') as $rank => $id) {
                (clone $pivot)->where('media_id', $id)->update(['pin_order' => $rank]);
            }
        });

        return response()->json(['message' => $validated['pinned'] ? 'Mis à la une.' : 'Retiré de la une.']);
    }

    /**
     * Définit la photo de couverture de l'album (média devant appartenir à l'album).
     */
    public function setCover(Request $request, Album $album)
    {
        Gate::authorize('manageMedia', $album);

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
        Gate::authorize('manageMedia', $album);

        $validated = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $mediaIds = $album->media()->pluck('media.id');

        $this->mediaService->bulkSetGeolocation(
            $mediaIds,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
        );

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

        // Personnes identifiées sur des photos de l'album, avec un compte :
        // via un cercle de l'album, elles le voient déjà ; sinon elles ne
        // voient que leurs photos — le partage peut leur ouvrir l'album.
        $listedIds = array_column($entries, 'user_id');
        $circleMemberIds = $this->albumCircleMemberIds($album);
        foreach ($this->taggedUsers($album, auth()->user())->whereNotIn('id', $listedIds) as $u) {
            $entries[] = $this->accessEntry($u, in_array($u->id, $circleMemberIds, true) ? 'circle' : 'tagged');
        }

        return response()->json($entries);
    }

    /**
     * « Donner accès aux personnes identifiées » : un accès pour chaque compte
     * identifié sur une photo de l'album qui ne le voit pas encore en entier
     * (ni propriétaire, ni accès accordé, ni membre d'un cercle de l'album).
     * Liste recalculée ici, jamais fournie par le client.
     */
    public function grantTaggedAccess(Album $album, PushNotifier $push)
    {
        Gate::authorize('grantAccess', $album);

        $already = $album->accesses()->pluck('user_id')
            ->push($album->user_id)
            ->merge($this->albumCircleMemberIds($album))
            ->all();
        $users = $this->taggedUsers($album, auth()->user())->whereNotIn('id', $already);

        foreach ($users as $user) {
            $access = AlbumAccess::firstOrCreate(
                ['album_id' => $album->id, 'user_id' => $user->id],
                ['granted_by' => auth()->id()],
            );
            if ($access->wasRecentlyCreated) {
                $push->social(
                    $user,
                    new AlbumAccessGranted(PushNotification::firstName(auth()->user()->name), $album->name, $album->id),
                    auth()->user(),
                );
            }
        }

        $n = $users->count();

        return response()->json([
            'granted' => $n,
            'message' => $n ? "Accès accordé à {$n} personne".($n > 1 ? 's' : '').'.' : 'Tout le monde a déjà accès.',
        ]);
    }

    /**
     * Comptes (vraies personnes) dont la fiche est identifiée sur au moins une
     * photo de l'album PAR UN HUMAIN : nommée à la main (pas de visage d'elle
     * sur la photo) ou visage validé — jamais sur la seule foi d'une
     * association automatique. Avec $granter : seulement les comptes qu'il
     * peut voir (comme le choix manuel d'un membre).
     */
    private function taggedUsers(Album $album, ?User $granter = null)
    {
        $taggedPersonIds = DB::table('media_person as mp')
            ->join('album_media as am', 'am.media_id', '=', 'mp.media_id')
            ->where('am.album_id', $album->id)
            ->where(fn ($w) => $w
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('detected_faces as df')
                    ->whereColumn('df.media_id', 'mp.media_id')->whereColumn('df.person_id', 'mp.person_id'))
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('detected_faces as df')
                    ->whereColumn('df.media_id', 'mp.media_id')->whereColumn('df.person_id', 'mp.person_id')
                    ->whereNotNull('df.verified_at')))
            ->distinct()
            ->pluck('mp.person_id');

        return User::realPeople()
            ->whereIn('person_id', $taggedPersonIds)
            ->when($granter, fn ($q) => $q->visibleTo($granter))
            ->orderBy('name')
            ->get();
    }

    /** @return list<string> membres des cercles avec lesquels l'album est partagé */
    private function albumCircleMemberIds(Album $album): array
    {
        return DB::table('household_user')
            ->whereIn('household_id', $album->households()->pluck('households.id'))
            ->pluck('user_id')->unique()->values()->all();
    }

    private function accessEntry(User $user, string $origin, ?string $grantedBy = null): array
    {
        return [
            'user_id' => $user->id,
            'name' => $user->name,
            // Adresse e-mail : réservée à l'admin (pas d'annuaire pour tous).
            'email' => auth()->user()?->isAdmin() ? $user->email : null,
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
        $viewer = $request->user();
        $like = '%'.addcslashes($q, '%_\\').'%';

        // Pas d'annuaire de tous les comptes : seulement les personnes qui
        // partagent un cercle avec moi (l'admin voit tout le monde), et
        // l'adresse e-mail n'est montrée qu'à l'admin.
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
     * Accorder un accès à un compte (délégation possible, cf. AlbumPolicy).
     */
    public function grantAccess(Request $request, Album $album, PushNotifier $push)
    {
        Gate::authorize('grantAccess', $album);

        $validated = $request->validate([
            'user_id' => ['required', 'uuid', 'exists:users,id'],
        ]);

        if ($validated['user_id'] === $album->user_id) {
            return response()->json(['message' => 'Le propriétaire a déjà accès.'], 422);
        }

        $access = AlbumAccess::firstOrCreate(
            ['album_id' => $album->id, 'user_id' => $validated['user_id']],
            ['granted_by' => auth()->id()],
        );

        // Push « accès à l'album » — uniquement à la création de l'accès.
        if ($access->wasRecentlyCreated) {
            $push->social(
                User::findOrFail($validated['user_id']),
                new AlbumAccessGranted(PushNotification::firstName(auth()->user()->name), $album->name, $album->id),
                auth()->user(),
            );
        }

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

    /**
     * Cercles : ceux avec qui l'album est partagé, et ceux où le viewer peut
     * encore le partager (ses cercles ; tous pour l'admin).
     */
    public function circles(Request $request, Album $album)
    {
        Gate::authorize('view', $album);

        $user = $request->user();
        $shared = $album->households()->get(['households.id', 'households.name', 'households.kind']);
        $mine = $user->isAdmin()
            ? Household::orderBy('name')->get(['id', 'name', 'kind'])
            : $user->circles();

        $canGrant = Gate::allows('grantAccess', $album);

        return response()->json([
            'shared' => $shared->map(fn ($h) => [
                'id' => $h->id,
                'name' => $h->name,
                'kind' => $h->kind,
                'can_revoke' => $album->user_id === $user->id || $user->isAdmin() || $h->pivot->granted_by === $user->id,
            ])->values(),
            'available' => $canGrant
                ? $mine->whereNotIn('id', $shared->pluck('id'))
                    ->map(fn ($h) => ['id' => $h->id, 'name' => $h->name, 'kind' => $h->kind])->values()
                : [],
        ]);
    }

    /**
     * Partager l'album avec un cercle : quiconque a accès à l'album (comme
     * pour un compte), vers un cercle dont il est membre (l'admin : tout cercle).
     */
    public function shareWithCircle(Request $request, Album $album)
    {
        Gate::authorize('grantAccess', $album);

        $validated = $request->validate([
            'household_id' => ['required', 'uuid', 'exists:households,id'],
        ]);

        $user = $request->user();
        abort_unless(
            $user->isAdmin() || in_array($validated['household_id'], $user->householdIds(), true),
            403,
            'Vous ne pouvez partager qu\'avec vos propres cercles.'
        );

        $album->households()->syncWithoutDetaching([
            $validated['household_id'] => ['granted_by' => $user->id],
        ]);

        return $this->circles($request, $album);
    }

    /**
     * Retirer le partage avec un cercle : le propriétaire, l'admin, ou celui
     * qui l'a accordé.
     */
    public function unshareWithCircle(Request $request, Album $album, Household $household)
    {
        $user = $request->user();
        $pivot = $album->households()->whereKey($household->id)->first()?->pivot;
        abort_unless($pivot, 404);
        abort_unless($album->user_id === $user->id || $user->isAdmin() || $pivot->granted_by === $user->id, 403);

        $album->households()->detach($household->id);

        return $this->circles($request, $album);
    }
}
