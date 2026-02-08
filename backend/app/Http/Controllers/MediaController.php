<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MediaController extends Controller
{
    protected MediaService $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Get the current user ID (authenticated or default).
     * Temporary solution until authentication is implemented.
     */
    private function getCurrentUserId(): string
    {
        // If authenticated, use auth user
        if (auth()->check()) {
            return auth()->id();
        }

        // Otherwise, use first user as default
        $user = User::first();
        if (!$user) {
            throw new \Exception('No users found. Please create a user first.');
        }

        return $user->id;
    }
    /**
     * Display a listing of media.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['type', 'search', 'tags']);
        $media = $this->mediaService->getPaginatedMedia($filters);

        if ($request->wantsJson()) {
            return response()->json($media);
        }

        return Inertia::render('Media/Index', [
            'media' => $media,
            'filters' => $filters,
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
            'file' => 'required|file|max:2097152|mimes:jpg,jpeg,png,gif,webp,mp4,mov,avi,pdf,doc,docx',
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
        $media->load(['user', 'tags', 'conversions', 'metadata', 'people', 'detectedFaces.person']);

        // Generate signed URL
        $media->url = $this->mediaService->getSignedUrl($media);

        // Generate signed URLs for conversions
        if ($media->conversions) {
            $media->conversions->transform(function ($conversion) {
                $conversion->url = $this->mediaService->getSignedUrl($media, $conversion->file_path);
                return $conversion;
            });
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
     * Download the specified media.
     */
    public function download(Media $media)
    {
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
