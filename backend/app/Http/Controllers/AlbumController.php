<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlbumController extends Controller
{
    protected MediaService $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    public function index(Request $request)
    {
        $albums = Album::where('user_id', auth()->id())
            ->withCount('media')
            ->with(['coverMedia.conversions'])
            ->orderBy('updated_at', 'desc')
            ->get();

        $albums->transform(function ($album) {
            if ($album->coverMedia) {
                $album->cover_url = $this->getCoverUrl($album->coverMedia);
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'cover_media_id' => 'nullable|exists:media,id',
            'is_public' => 'boolean',
        ]);

        $album = Album::create([
            ...$validated,
            'user_id' => auth()->id(),
            'is_public' => $validated['is_public'] ?? false,
        ]);

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
        if ($album->user_id !== auth()->id()) {
            abort(403);
        }

        $album->load(['coverMedia.conversions', 'media.conversions', 'media.tags']);
        $album->loadCount('media');

        $album->media->transform(function ($media) {
            $media->url = $this->mediaService->getSignedUrl($media);
            if ($media->conversions) {
                $media->conversions->transform(function ($conv) {
                    $conv->url = $this->mediaService->getSignedUrl($conv, $conv->file_path);
                    return $conv;
                });
            }
            return $media;
        });

        if ($album->coverMedia) {
            $album->cover_url = $this->getCoverUrl($album->coverMedia);
        }

        $album->share_url = $album->getShareUrl();

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

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'cover_media_id' => 'nullable|exists:media,id',
            'is_public' => 'boolean',
        ]);

        $album->update($validated);

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

        $maxOrder = $album->media()->max('album_media.order') ?? -1;

        foreach ($validated['media_ids'] as $index => $mediaId) {
            if (!$album->media()->where('media_id', $mediaId)->exists()) {
                $album->media()->attach($mediaId, ['order' => $maxOrder + $index + 1]);
            }
        }

        if (!$album->cover_media_id && count($validated['media_ids']) > 0) {
            $album->update(['cover_media_id' => $validated['media_ids'][0]]);
        }

        return response()->json([
            'message' => 'Medias ajoutes a l\'album',
        ]);
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
                $media->conversions->transform(function ($conv) {
                    $conv->url = $this->mediaService->getSignedUrl($conv, $conv->file_path);
                    return $conv;
                });
            }
            return $media;
        });

        if ($album->coverMedia) {
            $album->cover_url = $this->getCoverUrl($album->coverMedia);
        }

        return Inertia::render('Albums/Shared', [
            'album' => $album,
        ]);
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
}
