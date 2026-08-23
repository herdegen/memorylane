<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

        $hasGeo = fn ($q) => $q->whereNotNull('latitude')->whereNotNull('longitude');

        // Médias géolocalisés HORS album : ils restent des marqueurs média.
        // Ceux appartenant à un album sont représentés par le marqueur d'album
        // (cliquer mène alors à l'album, pas à la photo).
        $query = Media::with(['user', 'tags', 'metadata', 'conversions'])
            ->accessibleBy(auth()->user())
            ->whereDoesntHave('albums')
            ->whereHas('metadata', $hasGeo)
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

        $media = $query->get()->map(fn ($item) => [
            'id' => $item->id,
            'type' => $item->type,
            'original_name' => $item->original_name,
            'taken_at' => $item->taken_at,
            'latitude' => $item->metadata->latitude,
            'longitude' => $item->metadata->longitude,
            'altitude' => $item->metadata->altitude,
            'thumbnail_url' => $this->thumbnailUrl($item),
            'tags' => $item->tags,
        ]);

        // Albums géolocalisés (au moins un média avec coordonnées) : un seul
        // marqueur par album, positionné sur son premier média géolocalisé.
        $albums = Album::query()
            ->accessibleBy(auth()->user())
            ->whereHas('media', fn ($q) => $q->whereHas('metadata', $hasGeo))
            ->with(['media' => function ($q) use ($hasGeo) {
                $q->whereHas('metadata', $hasGeo)
                  ->with(['metadata', 'conversions'])
                  ->orderBy('taken_at', 'desc');
            }])
            ->get()
            ->map(function ($album) {
                $first = $album->media->first();
                if (! $first || ! $first->metadata) {
                    return null;
                }

                return [
                    'id' => $album->id,
                    'name' => $album->name,
                    'latitude' => $first->metadata->latitude,
                    'longitude' => $first->metadata->longitude,
                    'media_count' => $album->media->count(),
                    'thumbnail_url' => $this->thumbnailUrl($first),
                ];
            })
            ->filter()
            ->values();

        return response()->json([
            'media' => $media,
            'albums' => $albums,
        ]);
    }

    /**
     * Couche « où vit la famille » : heatmap volontairement grossière des
     * adresses de résidence. Coordonnées arrondies à 0,01° (~1 km) CÔTÉ
     * SERVEUR — les coordonnées précises ne quittent jamais le backend — et
     * agrégées par point arrondi (le poids = nombre de personnes), sans
     * aucun identifiant. Toutes les adresses comptent, opt-in ou non :
     * l'opt-in ne gouverne que l'affichage de l'adresse précise sur la fiche.
     */
    public function heatmap()
    {
        $points = Person::query()
            ->whereNotNull('address_latitude')
            ->whereNotNull('address_longitude')
            ->get(['address_latitude', 'address_longitude'])
            ->groupBy(fn ($p) => round((float) $p->address_latitude, 2) . ',' . round((float) $p->address_longitude, 2))
            ->map(function ($group, $key) {
                [$lat, $lng] = explode(',', $key);

                return [(float) $lat, (float) $lng, $group->count()];
            })
            ->values();

        return response()->json(['points' => $points]);
    }

    /**
     * URL signée de la miniature (conversion thumbnail, sinon l'original).
     */
    private function thumbnailUrl(Media $item): string
    {
        return $this->mediaService->thumbnailUrl($item, ['thumbnail']);
    }

    /**
     * Update geolocation for a media item.
     */
    public function updateGeolocation(Request $request, Media $media)
    {
        Gate::authorize('update', $media);

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
        Gate::authorize('update', $media);

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
        $media = Media::with(['user', 'tags', 'metadata', 'conversions'])
            ->accessibleBy(auth()->user())
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
            ->map(fn ($item) => [
                'id' => $item->id,
                'type' => $item->type,
                'original_name' => $item->original_name,
                'taken_at' => $item->taken_at,
                'latitude' => $item->metadata->latitude,
                'longitude' => $item->metadata->longitude,
                'thumbnail_url' => $this->thumbnailUrl($item),
                'tags' => $item->tags,
            ]);

        return response()->json($media);
    }
}
