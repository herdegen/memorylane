<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
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
     * Display a listing of media.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['type', 'search', 'tags', 'duration_min', 'duration_max', 'resolution', 'video_codec']);
        $media = $this->mediaService->getPaginatedMedia($filters);

        if ($request->wantsJson()) {
            return response()->json($media);
        }

        // Available codecs for the video filter panel (scoped à l'utilisateur)
        $availableCodecs = Media::where('type', 'video')
            ->where('user_id', $this->getCurrentUserId())
            ->whereNotNull('video_codec')
            ->distinct()
            ->orderBy('video_codec')
            ->pluck('video_codec');

        return Inertia::render('Media/Index', [
            'media'          => $media,
            'filters'        => $filters,
            'availableCodecs' => $availableCodecs,
        ]);
    }

    /**
     * IDs de tous les médias correspondant aux filtres courants (sans
     * pagination). Sert au « tout sélectionner » de la galerie pour couvrir
     * aussi les pages non encore chargées.
     */
    public function ids(Request $request)
    {
        $filters = $request->only(['type', 'search', 'tags', 'duration_min', 'duration_max', 'resolution', 'video_codec']);

        return response()->json([
            'ids' => $this->mediaService->getFilteredMediaIds($filters),
        ]);
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

            // Generate signed URL for response
            $media->url = $this->mediaService->getSignedUrl($media);

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
            'user', 'tags', 'conversions', 'metadata', 'people', 'detectedFaces.person',
            'sourceMedia', 'clips.conversions',
        ]);

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

        return Inertia::render('Media/Show', [
            'media' => $media,
        ]);
    }

    /**
     * Update the specified media.
     */
    public function update(Request $request, Media $media)
    {
        if ($media->user_id !== $this->getCurrentUserId()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:2000',
            'taken_at' => 'nullable|date',
        ]);

        $media->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Media mis a jour',
                'media' => $media,
            ]);
        }

        return redirect()->back()->with('success', 'Media mis a jour');
    }

    /**
     * Remove the specified media from storage.
     */
    public function destroy(Media $media)
    {
        // Authorization check (temporary until auth is implemented)
        if ($media->user_id !== $this->getCurrentUserId()) {
            return response()->json([
                'error' => 'Unauthorized'
            ], 403);
        }

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
    public function storeClips(Request $request, Media $media)
    {
        if ($media->user_id !== $this->getCurrentUserId()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($media->type !== 'video') {
            return response()->json(['error' => 'Seules les vidéos peuvent être découpées.'], 422);
        }

        if ($media->source_media_id !== null) {
            return response()->json(['error' => 'Un clip ne peut pas être re-découpé.'], 422);
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
            'message' => 'Découpage lancé. Les clips apparaîtront dans la galerie dans quelques instants.',
            'count' => count($validated['segments']),
        ], 202);
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
