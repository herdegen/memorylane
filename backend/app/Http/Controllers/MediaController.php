<?php

namespace App\Http\Controllers;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class MediaController extends Controller
{
    /**
     * Display a listing of media.
     */
    public function index(Request $request)
    {
        $query = Media::with(['user'])
            ->orderBy('taken_at', 'desc')
            ->orderBy('uploaded_at', 'desc');

        // Filter by type if provided
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Search by name if provided
        if ($request->has('search')) {
            $query->where('original_name', 'like', '%' . $request->search . '%');
        }

        $media = $query->paginate(24);

        // Add signed URLs for each media item
        $media->getCollection()->transform(function ($item) {
            $item->url = Storage::disk('scaleway')->temporaryUrl(
                $item->file_path,
                now()->addHours(1)
            );
            return $item;
        });

        return Inertia::render('Media/Index', [
            'media' => $media,
            'filters' => $request->only(['type', 'search']),
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
            'file' => 'required|file|max:2097152', // 2GB max
            'file' => 'required|mimes:jpg,jpeg,png,gif,webp,mp4,mov,avi,pdf,doc,docx',
        ]);

        $file = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        // Determine media type
        $type = $this->determineMediaType($mimeType);

        // Generate unique file path
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        $filePath = "media/{$type}s/" . date('Y/m') . "/{$filename}";

        // Upload to S3
        try {
            Storage::disk('scaleway')->putFileAs(
                dirname($filePath),
                $file,
                basename($filePath),
                'private'
            );
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to upload file to storage',
                'message' => $e->getMessage()
            ], 500);
        }

        // Get image dimensions if it's an image
        $width = null;
        $height = null;
        if (str_starts_with($mimeType, 'image/')) {
            try {
                $imageSize = getimagesize($file->getRealPath());
                $width = $imageSize[0] ?? null;
                $height = $imageSize[1] ?? null;
            } catch (\Exception $e) {
                // Continue without dimensions
            }
        }

        // Create media record
        $media = Media::create([
            'user_id' => auth()->id(),
            'type' => $type,
            'original_name' => $originalName,
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'size' => $size,
            'width' => $width,
            'height' => $height,
            'uploaded_at' => now(),
        ]);

        // Generate signed URL for response
        $media->url = Storage::disk('scaleway')->temporaryUrl(
            $media->file_path,
            now()->addHours(1)
        );

        return response()->json([
            'message' => 'Media uploaded successfully',
            'media' => $media,
        ], 201);
    }

    /**
     * Display the specified media.
     */
    public function show(Media $media)
    {
        $media->load(['user']);

        // Generate signed URL
        $media->url = Storage::disk('scaleway')->temporaryUrl(
            $media->file_path,
            now()->addHours(1)
        );

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
            // Delete from S3
            if (Storage::disk('scaleway')->exists($media->file_path)) {
                Storage::disk('scaleway')->delete($media->file_path);
            }

            // Soft delete the media record
            $media->delete();

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
            $tempUrl = Storage::disk('scaleway')->temporaryUrl(
                $media->file_path,
                now()->addMinutes(5),
                [
                    'ResponseContentDisposition' => 'attachment; filename="' . $media->original_name . '"'
                ]
            );

            return redirect($tempUrl);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to download media',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Determine media type from MIME type.
     */
    private function determineMediaType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'photo';
        } elseif (str_starts_with($mimeType, 'video/')) {
            return 'video';
        } else {
            return 'document';
        }
    }
}
