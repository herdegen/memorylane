<?php

namespace App\Http\Controllers;

use App\Models\Media;
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
     * Display a listing of media.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['type', 'search']);
        $media = $this->mediaService->getPaginatedMedia($filters);

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
                auth()->id()
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
        $media->load(['user']);

        // Generate signed URL
        $media->url = $this->mediaService->getSignedUrl($media);

        return Inertia::render('Media/Show', [
            'media' => $media,
        ]);
    }

    /**
     * Remove the specified media from storage.
     */
    public function destroy(Media $media)
    {
        // Authorization check
        if ($media->user_id !== auth()->id()) {
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
