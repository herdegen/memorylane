<?php

namespace App\Http\Controllers;

use App\Models\Household;
use App\Models\Media;
use App\Models\Person;
use App\Services\MediaService;
use App\Services\PushNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class MediaController extends Controller
{
    protected MediaService $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * ID de l'utilisateur authentifié (les routes média sont derrière le
     * middleware auth, donc toujours défini).
     */
    private function getCurrentUserId(): ?string
    {
        return auth()->id();
    }
    /**
     * Paramètres de la galerie (filtres + tri), repris tels quels dans l'URL.
     */
    private const FILTER_KEYS = [
        'type', 'search', 'tags', 'duration_min', 'duration_max', 'resolution', 'video_codec',
        'sort', 'year_from', 'year_to', 'undated', 'people', 'people_mode',
    ];

    /**
     * Display a listing of media.
     */
    /**
     * Sélecteur de photos (moments de la frise…) : photos visibles dans le
     * contexte courant, par onglet — récentes (mes derniers envois), autour
     * d'une date, avec une personne, d'un album — avec une recherche texte.
     */
    public function pick(Request $request)
    {
        $validated = $request->validate([
            'tab' => 'required|in:recent,date,person,album',
            'around' => 'nullable|date_format:Y-m-d',
            'person_id' => ['nullable', 'uuid', new \App\Rules\VisiblePerson],
            'album_id' => 'nullable|uuid',
            'q' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1',
        ]);
        $user = $request->user();

        $query = Media::inContext($user)->where('type', 'photo')->where('is_source', false);

        match ($validated['tab']) {
            'recent' => $query->where('user_id', $user->id)->orderByDesc('uploaded_at')->orderByDesc('id'),
            'date' => isset($validated['around'])
                // Dans l'année autour de la date, les plus proches d'abord.
                ? $query->whereBetween('taken_at', [
                    \Carbon\Carbon::parse($validated['around'])->subYear(),
                    \Carbon\Carbon::parse($validated['around'])->addYear()->endOfDay(),
                ])->orderByRaw('abs(extract(epoch from (taken_at - ?::timestamp)))', [$validated['around']])
                : $query->whereRaw('1 = 0'),
            'person' => isset($validated['person_id'])
                ? $query->whereHas('people', fn ($p) => $p->where('people.id', $validated['person_id']))->orderByRaw('taken_at desc nulls last')
                : $query->whereRaw('1 = 0'),
            'album' => isset($validated['album_id'])
                ? $query->whereHas('albums', fn ($a) => $a->whereKey(
                    \App\Models\Album::accessibleBy($user)->whereKey($validated['album_id'])->value('id') ?? '00000000-0000-0000-0000-000000000000'
                ))->orderByRaw('taken_at asc nulls last')
                : $query->whereRaw('1 = 0'),
        };
        // Départage stable : sans lui, la pagination répète ou saute des photos
        // de même date.
        $query->orderBy('media.id');

        if ($q = trim($validated['q'] ?? '')) {
            // Sans accents ni casse des deux côtés : « asterix » trouve « Astérix ».
            $query->where(fn ($w) => $w->whereUnaccentLike(['media.title', 'media.original_name'], $q)
                ->orWhereHas('albums', fn ($a) => $a->whereUnaccentLike('albums.name', $q))
                ->orWhereHas('people', fn ($p) => $p->whereUnaccentLike('people.name', $q)));
        }

        $page = $query->with('conversions')->simplePaginate(60, ['media.*']);

        return response()->json([
            'data' => collect($page->items())->map(fn (Media $m) => [
                'id' => $m->id,
                'title' => $m->title,
                'taken_at' => $m->taken_at?->toDateString(),
                'thumbnail_url' => $this->mediaService->thumbnailUrl($m, ['small', 'thumbnail']),
            ])->values(),
            'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null,
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->only(self::FILTER_KEYS);
        $media = $this->mediaService->getPaginatedMedia($filters);

        if ($request->wantsJson()) {
            return response()->json($media);
        }

        $userId = $this->getCurrentUserId();
        $gallery = fn () => Media::where('user_id', $userId)->where('is_source', false);

        // Available codecs for the video filter panel (scoped à l'utilisateur)
        $availableCodecs = $gallery()->where('type', 'video')
            ->whereNotNull('video_codec')
            ->distinct()
            ->orderBy('video_codec')
            ->pluck('video_codec');

        return Inertia::render('Media/Index', [
            'media'          => $media,
            // Objet JSON même vide (un [] ferait lire Array.prototype.sort côté JS).
            'filters'        => (object) $filters,
            'availableCodecs' => $availableCodecs,
            // Années de prise de vue présentes, pour le filtre de période.
            'years' => $gallery()->whereNotNull('taken_at')
                ->selectRaw('DISTINCT EXTRACT(YEAR FROM taken_at)::int AS year')
                ->orderByDesc('year')
                ->pluck('year'),
            // Compteurs des filtres « à trier » (Sans date, Sans personne…).
            'counts' => [
                'undated' => $gallery()->whereNull('taken_at')->count(),
                'no_people' => $gallery()->whereDoesntHave('people')->where('type', '!=', 'document')->count(),
                'unidentified' => $gallery()->whereHas('detectedFaces', fn ($q) => $q->unidentified($request->user()))->count(),
            ],
            // Noms des personnes filtrées (chips), limités aux fiches visibles.
            'filterPeople' => Person::visibleTo($request->user())
                ->whereIn('people.id', MediaService::personIdsFilter($filters))
                ->get(['people.id', 'people.name']),
        ]);
    }

    /**
     * IDs de tous les médias correspondant aux filtres courants (sans
     * pagination). Sert au « tout sélectionner » de la galerie pour couvrir
     * aussi les pages non encore chargées.
     */
    public function ids(Request $request)
    {
        $filters = $request->only(self::FILTER_KEYS);

        return response()->json([
            'ids' => $this->mediaService->getFilteredMediaIds($filters),
        ]);
    }

    /**
     * Personnes présentes sur les médias de l'utilisateur, avec leur nombre
     * de médias (filtre « Personnes » de la galerie, chargé à l'ouverture).
     */
    public function people(Request $request)
    {
        $counts = DB::table('media_person')
            ->join('media', 'media.id', '=', 'media_person.media_id')
            ->where('media.user_id', $this->getCurrentUserId())
            ->where('media.is_source', false)
            ->whereNull('media.deleted_at')
            ->groupBy('media_person.person_id')
            ->selectRaw('media_person.person_id, COUNT(DISTINCT media.id) AS media_count')
            ->pluck('media_count', 'person_id');

        $people = Person::visibleTo($request->user())
            ->whereIn('people.id', $counts->keys())
            ->get(['people.id', 'people.name', 'people.first_name', 'people.last_name', 'people.maiden_name', 'people.birth_date'])
            ->map(fn (Person $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'first_name' => $p->first_name,
                'last_name' => $p->last_name,
                'maiden_name' => $p->maiden_name,
                'birth_date' => $p->birth_date?->format('Y-m-d'),
                'media_count' => (int) $counts[$p->id],
            ])
            ->sortByDesc('media_count')
            ->values();

        return response()->json(['people' => $people]);
    }

    /**
     * Show the form for uploading new media.
     */
    public function create()
    {
        return Inertia::render('Media/Upload');
    }

    /**
     * Store newly uploaded media.
     */
    public function store(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:2097152|mimes:jpg,jpeg,png,gif,webp,heic,heif,mp4,mov,avi,pdf,doc,docx',
        ]);

        try {
            $media = $this->mediaService->uploadMedia(
                $request->file('file'),
                $this->getCurrentUserId()
            );

            $media->url = $this->mediaService->fileUrl($media);

            return response()->json([
                'message' => 'Media uploaded successfully',
                'media' => $media,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to upload file to storage',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified media.
     */
    public function show(Media $media)
    {
        Gate::authorize('view', $media);

        $media->load([
            // Propriétaire : juste son nom (pas d'e-mail ni de préférences).
            'user:id,name', 'tags', 'conversions', 'metadata', 'people', 'detectedFaces.person',
            'sourceMedia', 'clips.conversions',
        ]);

        // Personnes d'un autre cercle : leur nom seul (on voit qui est sur une
        // photo qu'on a le droit de voir), sans le reste de leur fiche.
        $viewer = auth()->user();
        $visiblePeople = \App\Models\Person::visibleTo($viewer)
            ->whereIn('people.id', $media->people->pluck('id'))
            ->pluck('people.id')->flip();
        $media->setRelation('people', $media->people
            ->map(fn ($p) => isset($visiblePeople[$p->id]) ? $p : \App\Models\Person::restrictedStub($p))
            ->values());
        \App\Models\DetectedFace::maskPeopleFor($media->detectedFaces, $viewer);

        // URLs signées du média + de ses conversions
        $this->mediaService->hydrateSignedUrls([$media]);

        // Vignettes signées des clips (pour la liste sur une vidéo source)
        if ($media->clips) {
            $this->mediaService->hydrateSignedUrls($media->clips);
        }

        // Append computed accessor for videos
        if ($media->type === 'video') {
            $media->append('resolution_label');
        }

        $viewer = auth()->user();
        $voiceNotes = $media->voiceNotes()->with('person', 'user')->get()
            ->map(fn ($note) => VoiceNoteController::payload($note, $viewer))
            ->values();

        // Ce média attend que le viewer valide son affichage sur sa fiche.
        $mine = $viewer->person_id ? $media->people->firstWhere('id', $viewer->person_id) : null;
        $ficheRequest = $mine?->pivot?->fiche_status === \App\Services\FichePublisher::PENDING
            ? ['by' => \App\Models\User::find($mine->pivot->fiche_added_by)?->name]
            : null;

        return Inertia::render('Media/Show', [
            'media' => $media,
            'voiceNotes' => $voiceNotes,
            'ficheRequest' => $ficheRequest,
            // « Ajouter une personne » : présents sur l'album, même d'un autre cercle
            'albumPeople' => app(\App\Services\Vision\AlbumParticipants::class)->identifiedAround($media, auth()->user()),
            // Moments de frise qui utilisent cette photo (illustration ou galerie),
            // sur des fiches visibles du visiteur.
            'moments' => \App\Models\LifeEvent::query()
                ->where(fn ($q) => $q->where('media_id', $media->id)
                    ->orWhereHas('photos', fn ($p) => $p->where('media.id', $media->id)))
                ->whereHas('person', fn ($p) => $p->visibleTo($viewer))
                ->with('person:id,name')
                ->orderBy('event_date')
                ->get()
                ->map(fn ($ev) => [
                    'id' => $ev->id,
                    'title' => $ev->title,
                    'date' => $ev->event_date?->toDateString(),
                    'person' => ['id' => $ev->person->id, 'name' => $ev->person->name],
                ])->values(),
        ]);
    }

    /**
     * Update the specified media.
     */
    public function update(Request $request, Media $media)
    {
        Gate::authorize('update', $media);

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'taken_at' => 'nullable|date',
        ]);

        $media->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('Media mis a jour'),
                'media' => $media,
            ]);
        }

        return redirect()->back()->with('success', __('Media mis a jour'));
    }

    /**
     * Modification de masse : applique une date de prise de vue à une
     * sélection. Seuls les médias appartenant à l'appelant sont modifiés
     * (les autres sont ignorés et comptés dans `skipped`).
     */
    public function bulkUpdateTakenAt(Request $request)
    {
        $validated = $request->validate([
            'media_ids' => 'required|array|min:1|max:500',
            'media_ids.*' => 'uuid',
            'taken_at' => 'required|date',
        ]);

        $updated = Media::whereIn('id', $validated['media_ids'])
            ->where('user_id', $request->user()->id)
            ->update(['taken_at' => $validated['taken_at']]);

        return response()->json([
            'message' => trans_choice('Date appliquée à :count média|Date appliquée à :count médias', $updated),
            'updated' => $updated,
            'skipped' => count($validated['media_ids']) - $updated,
        ]);
    }

    /**
     * Modification de masse : applique une position GPS à une sélection.
     * Même règle de propriété que bulkUpdateTakenAt.
     */
    public function bulkUpdateGeolocation(Request $request)
    {
        $validated = $request->validate([
            'media_ids' => 'required|array|min:1|max:500',
            'media_ids.*' => 'uuid',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'altitude' => 'nullable|numeric',
        ]);

        $ownedIds = Media::whereIn('id', $validated['media_ids'])
            ->where('user_id', $request->user()->id)
            ->pluck('id');

        $this->mediaService->bulkSetGeolocation(
            $ownedIds,
            (float) $validated['latitude'],
            (float) $validated['longitude'],
            isset($validated['altitude']) ? (float) $validated['altitude'] : null,
        );

        return response()->json([
            'message' => trans_choice('Position appliquée à :count média|Position appliquée à :count médias', $ownedIds->count()),
            'updated' => $ownedIds->count(),
            'skipped' => count($validated['media_ids']) - $ownedIds->count(),
        ]);
    }

    /**
     * Partage de masse : ajoute une sélection au périmètre d'un foyer dont
     * l'appelant est membre. Seuls les médias APPARTENANT à l'appelant sont
     * partagés (les autres → `skipped` : partager le média d'un tiers
     * ouvrirait des accès à son insu).
     */
    public function bulkShareToHousehold(Request $request, PushNotifier $push)
    {
        $validated = $request->validate([
            'media_ids' => 'required|array|min:1|max:500',
            'media_ids.*' => 'uuid',
            'household_id' => 'required|uuid',
        ]);

        $household = Household::findOrFail($validated['household_id']);
        Gate::authorize('view', $household);

        $ownedIds = Media::whereIn('id', $validated['media_ids'])
            ->where('user_id', $request->user()->id)
            ->pluck('id');

        // Attache groupée en ignorant les déjà partagés (2 requêtes).
        $existing = $household->media()->whereIn('media.id', $ownedIds)->pluck('media.id')->all();
        $toAttach = $ownedIds
            ->diff($existing)
            ->mapWithKeys(fn ($id) => [$id => ['added_by' => $request->user()->id]])
            ->all();

        if ($toAttach !== []) {
            $household->media()->attach($toAttach);
            // Push groupé aux autres membres (fenêtre de 10 min, cf. PushNotifier).
            $push->householdShare($request->user(), $household, count($toAttach));
        }

        return response()->json([
            'message' => trans_choice(':count média partagé avec « :circle »|:count médias partagés avec « :circle »', $ownedIds->count(), ['circle' => $household->name]),
            'updated' => $ownedIds->count(),
            'skipped' => count($validated['media_ids']) - $ownedIds->count(),
        ]);
    }

    /**
     * Retire une sélection du périmètre d'un foyer. Même règle de propriété
     * que le partage : on ne retire que SES propres médias.
     */
    public function bulkRemoveFromHousehold(Request $request)
    {
        $validated = $request->validate([
            'media_ids' => 'required|array|min:1|max:500',
            'media_ids.*' => 'uuid',
            'household_id' => 'required|uuid',
        ]);

        $household = Household::findOrFail($validated['household_id']);
        Gate::authorize('view', $household);

        $ownedIds = Media::whereIn('id', $validated['media_ids'])
            ->where('user_id', $request->user()->id)
            ->pluck('id');

        $removed = $household->media()->detach($ownedIds->all());

        return response()->json([
            'message' => trans_choice(':count média retiré de « :circle »|:count médias retirés de « :circle »', $removed, ['circle' => $household->name]),
            'updated' => $removed,
            'skipped' => count($validated['media_ids']) - $ownedIds->count(),
        ]);
    }

    /**
     * Remove the specified media from storage.
     */
    public function destroy(Media $media)
    {
        Gate::authorize('delete', $media);

        try {
            $this->mediaService->deleteMedia($media);

            return response()->json([
                'message' => 'Media deleted successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to delete media',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Découpe une vidéo en clips. Chaque segment [start, end] donne un Media
     * distinct (traité en tâche de fond par SplitVideoIntoClips).
     */
    /**
     * Vidéo : l'image affichée à $time devient sa miniature et l'image où
     * l'on cherche les visages. Les cadres de l'ancienne image n'ont plus de
     * sens : effacés ; les personnes déjà identifiées restent sur la vidéo
     * (sans cadre). Propriétaire ou admin.
     */
    public function setVideoFrame(Request $request, Media $media)
    {
        abort_unless($media->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        abort_unless($media->type === 'video', 422, __('Seule une vidéo a une image à choisir.'));
        $validated = $request->validate(['time' => ['required', 'numeric', 'min:0', 'max:'.max(1, (float) $media->duration)]]);

        DB::transaction(function () use ($media, $validated) {
            $identified = $media->detectedFaces()->whereNotNull('person_id')->pluck('person_id')->unique();
            if ($identified->isNotEmpty()) {
                $media->people()->syncWithoutDetaching($identified->mapWithKeys(fn ($id) => [$id => ['face_coordinates' => null]])->all());
            }
            $media->detectedFaces()->get()->each->delete();
            $media->forceFill(['thumbnail_at' => (float) $validated['time']])->save();
            $media->metadata()->update(['vision_status' => null, 'vision_faces_count' => 0]);
        });

        // Miniatures refaites depuis cette image, puis visages recherchés dessus.
        \App\Jobs\GenerateMediaConversions::dispatch($media->fresh());

        return response()->json(['message' => __('Nouvelle miniature en préparation.')]);
    }

    public function storeClips(Request $request, Media $media)
    {
        Gate::authorize('update', $media);

        if ($media->type !== 'video') {
            return response()->json(['error' => __('Seules les vidéos peuvent être découpées.')], 422);
        }

        if ($media->source_media_id !== null) {
            return response()->json(['error' => __('Un clip ne peut pas être re-découpé.')], 422);
        }

        $maxEnd = $media->duration ? (float) $media->duration + 1 : null; // +1s de tolérance d'arrondi

        $validated = $request->validate([
            'segments' => 'required|array|min:1',
            'segments.*.start' => 'required|numeric|min:0',
            'segments.*.end' => 'required|numeric|gt:segments.*.start' . ($maxEnd ? "|max:{$maxEnd}" : ''),
            'segments.*.title' => 'nullable|string|max:255',
        ]);

        \App\Jobs\SplitVideoIntoClips::dispatch($media, $validated['segments']);

        return response()->json([
            'message' => __('Découpage lancé. Les clips apparaîtront dans la galerie dans quelques instants.'),
            'count' => count($validated['segments']),
        ], 202);
    }

    /**
     * Sert un fichier média (original ou conversion) : session + droit de
     * lecture vérifiés à CHAQUE chargement, puis redirection vers une URL S3
     * présignée très courte. C'est la seule porte d'entrée des <img>/<video>
     * du front — une URL copiée à un tiers non connecté ne donne rien.
     */
    public function file(Request $request, Media $media, ?string $conversion = null)
    {
        // Les admins doivent voir toutes les vignettes (panneau Filament :
        // doublons, visages…) ; pour les autres, la MediaPolicy s'applique.
        if (! $request->user()->isAdmin()) {
            Gate::authorize('view', $media);
        }

        $conversionPath = null;
        if ($conversion !== null) {
            $conversionModel = $media->conversions()->where('conversion_name', $conversion)->first();
            abort_unless($conversionModel, 404);
            $conversionPath = $conversionModel->file_path;
        }

        // URL stable sur l'heure + redirection mise en cache par le navigateur.
        return $this->mediaService->cachedFileRedirect($media, $conversionPath);
    }

    /**
     * Download the specified media.
     */
    public function download(Media $media)
    {
        Gate::authorize('view', $media);

        try {
            $downloadUrl = $this->mediaService->getDownloadUrl($media);
            return redirect($downloadUrl);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to download media',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
