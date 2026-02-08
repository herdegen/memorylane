<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeMediaWithVision;
use App\Models\DetectedFace;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisionController extends Controller
{
    /**
     * Get detected faces for a media.
     */
    public function faces(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        $faces = $media->detectedFaces()
            ->with('person')
            ->whereIn('status', ['unmatched', 'matched'])
            ->get();

        return response()->json($faces);
    }

    /**
     * Match a detected face to a person.
     */
    public function matchFace(Request $request, DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeMedia($detectedFace->media);

        $validated = $request->validate([
            'person_id' => 'required|uuid|exists:people,id',
        ]);

        // Update the detected face
        $detectedFace->update([
            'person_id' => $validated['person_id'],
            'status' => 'matched',
        ]);

        // Also create/update the media_person pivot with face_coordinates
        $detectedFace->media->people()->syncWithoutDetaching([
            $validated['person_id'] => [
                'face_coordinates' => json_encode($detectedFace->bounding_box),
            ],
        ]);

        $detectedFace->load('person');

        return response()->json($detectedFace);
    }

    /**
     * Dismiss a detected face.
     */
    public function dismissFace(DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeMedia($detectedFace->media);

        $detectedFace->update(['status' => 'dismissed']);

        return response()->json(['message' => 'Face dismissed']);
    }

    /**
     * Get vision labels for a media.
     */
    public function labels(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        $metadata = $media->metadata;

        return response()->json([
            'labels' => $metadata?->vision_labels ?? [],
            'status' => $metadata?->vision_status,
        ]);
    }

    /**
     * Re-run vision analysis on a media.
     */
    public function reanalyze(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        if ($media->type !== 'photo') {
            return response()->json(['message' => 'Only photos can be analyzed'], 422);
        }

        // Delete existing detected faces
        $media->detectedFaces()->delete();

        // Reset vision status
        if ($media->metadata) {
            $media->metadata->update([
                'vision_status' => 'pending',
                'vision_labels' => null,
                'vision_error' => null,
                'vision_faces_count' => 0,
                'vision_processed_at' => null,
            ]);
        }

        // Re-dispatch the analysis job
        AnalyzeMediaWithVision::dispatch($media);

        return response()->json(['message' => 'Analysis re-queued']);
    }

    /**
     * Get vision processing status for a media.
     */
    public function status(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        $metadata = $media->metadata;

        return response()->json([
            'status' => $metadata?->vision_status,
            'provider' => $metadata?->vision_provider,
            'processed_at' => $metadata?->vision_processed_at,
            'error' => $metadata?->vision_error,
            'faces_count' => $metadata?->vision_faces_count ?? 0,
        ]);
    }

    /**
     * Ensure the authenticated user owns the media.
     */
    private function authorizeMedia(Media $media): void
    {
        if ($media->user_id !== auth()->id()) {
            abort(403);
        }
    }
}
