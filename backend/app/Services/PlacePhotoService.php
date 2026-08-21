<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Photo d'illustration d'un lieu, trouvée automatiquement sur Wikimedia
 * Commons (géo-recherche autour des coordonnées — églises, mairies,
 * monuments photographiés par la communauté). Cache durable par position
 * arrondie. Meilleur effort : null si rien de photogénique à proximité.
 */
class PlacePhotoService
{
    private const ENDPOINT = 'https://commons.wikimedia.org/w/api.php';

    private const HIT_TTL_DAYS = 365;
    private const MISS_TTL_DAYS = 14;

    public function photoFor(float $latitude, float $longitude): ?string
    {
        $key = sprintf('placephoto:%.4f,%.4f', $latitude, $longitude);

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $url = $this->fetch($latitude, $longitude);
        Cache::put($key, $url ?? 'miss', now()->addDays($url ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));

        return $url;
    }

    private function fetch(float $latitude, float $longitude): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
            ])->timeout(6)->get(self::ENDPOINT, [
                'action' => 'query',
                'format' => 'json',
                'generator' => 'geosearch',
                'ggscoord' => "{$latitude}|{$longitude}",
                'ggsradius' => 300,
                'ggslimit' => 5,
                'ggsnamespace' => 6, // fichiers
                'prop' => 'imageinfo',
                'iiprop' => 'url|mime',
                'iiurlwidth' => 1280,
            ]);

            if (! $response->ok()) {
                return null;
            }

            $pages = $response->json('query.pages') ?? [];
            foreach ($pages as $page) {
                $info = $page['imageinfo'][0] ?? null;
                if ($info && str_starts_with($info['mime'] ?? '', 'image/') && ! str_contains($info['mime'], 'svg')) {
                    return $info['thumburl'] ?? $info['url'] ?? null;
                }
            }

            return null;
        } catch (\Throwable $e) {
            Log::warning('PlacePhotoService: échec Wikimedia', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
