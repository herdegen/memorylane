<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class MapController extends Controller
{
    protected MediaService $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    /**
     * Display the map view with geolocated media.
     */
    public function index(Request $request)
    {
        $filters = $request->only(['type', 'search', 'tags']);

        return Inertia::render('Map/Index', [
            'filters' => $filters,
        ]);
    }

    /**
     * Get all media with geolocation data.
     */
    public function getGeolocatedMedia(Request $request)
    {
        $filters = $request->only(['type', 'search', 'tags']);

        $query = Media::with(['user', 'tags', 'metadata'])
            ->whereHas('metadata', function ($q) {
                $q->whereNotNull('latitude')
                  ->whereNotNull('longitude');
            })
            ->orderBy('taken_at', 'desc');

        // Apply filters
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }

        if (isset($filters['tags']) && !empty($filters['tags'])) {
            $tagIds = is_array($filters['tags']) ? $filters['tags'] : [$filters['tags']];
            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            });
        }

        $media = $query->get()->map(function ($item) {
            // Generate signed URL for thumbnail
            $thumbnailConversion = $item->conversions->firstWhere('conversion_name', 'thumbnail');
            if ($thumbnailConversion) {
                $item->thumbnail_url = $this->mediaService->getSignedUrl($item, $thumbnailConversion->file_path);
            } else {
                $item->thumbnail_url = $this->mediaService->getSignedUrl($item);
            }

            return [
                'id' => $item->id,
                'type' => $item->type,
                'original_name' => $item->original_name,
                'taken_at' => $item->taken_at,
                'latitude' => $item->metadata->latitude,
                'longitude' => $item->metadata->longitude,
                'altitude' => $item->metadata->altitude,
                'thumbnail_url' => $item->thumbnail_url,
                'tags' => $item->tags,
            ];
        });

        return response()->json($media);
    }

    /**
     * Update geolocation for a media item.
     */
    public function updateGeolocation(Request $request, Media $media)
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'altitude' => 'nullable|numeric',
        ]);

        $media->metadata()->updateOrCreate(
            ['media_id' => $media->id],
            [
                'latitude' => $validated['latitude'],
                'longitude' => $validated['longitude'],
                'altitude' => $validated['altitude'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Geolocation updated successfully',
            'metadata' => $media->fresh()->metadata,
        ]);
    }

    /**
     * Remove geolocation from a media item.
     */
    public function removeGeolocation(Media $media)
    {
        $media->metadata()->update([
            'latitude' => null,
            'longitude' => null,
            'altitude' => null,
        ]);

        return response()->json([
            'message' => 'Geolocation removed successfully',
        ]);
    }

    /**
     * Search for a location using Nominatim (OpenStreetMap).
     */
    public function searchLocation(Request $request)
    {
        $validated = $request->validate([
            'query' => 'required|string|min:3',
        ]);

        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
            ])->get('https://nominatim.openstreetmap.org/search', [
                'q' => $validated['query'],
                'format' => 'json',
                'limit' => 10,
                'addressdetails' => 1,
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'error' => 'Failed to search location'
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Location search failed',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get media near a specific location.
     */
    public function getNearbyMedia(Request $request)
    {
        $validated = $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'nullable|numeric|min:0.1|max:100', // radius in kilometers
        ]);

        $lat = $validated['latitude'];
        $lon = $validated['longitude'];
        $radius = $validated['radius'] ?? 5; // default 5km

        // Haversine formula to calculate distance
        $media = Media::with(['user', 'tags', 'metadata'])
            ->whereHas('metadata', function ($q) use ($lat, $lon, $radius) {
                $q->whereNotNull('latitude')
                  ->whereNotNull('longitude')
                  ->whereRaw("
                    (6371 * acos(
                        cos(radians(?)) * cos(radians(latitude)) *
                        cos(radians(longitude) - radians(?)) +
                        sin(radians(?)) * sin(radians(latitude))
                    )) <= ?
                ", [$lat, $lon, $lat, $radius]);
            })
            ->orderBy('taken_at', 'desc')
            ->get()
            ->map(function ($item) {
                $thumbnailConversion = $item->conversions->firstWhere('conversion_name', 'thumbnail');
                if ($thumbnailConversion) {
                    $item->thumbnail_url = $this->mediaService->getSignedUrl($item, $thumbnailConversion->file_path);
                } else {
                    $item->thumbnail_url = $this->mediaService->getSignedUrl($item);
                }

                return [
                    'id' => $item->id,
                    'type' => $item->type,
                    'original_name' => $item->original_name,
                    'taken_at' => $item->taken_at,
                    'latitude' => $item->metadata->latitude,
                    'longitude' => $item->metadata->longitude,
                    'thumbnail_url' => $item->thumbnail_url,
                    'tags' => $item->tags,
                ];
            });

        return response()->json($media);
    }
}
