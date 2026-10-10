<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Photo d'illustration d'un lieu, trouvée automatiquement :
 *  1. l'image de l'article Wikipédia (fr) de la localité — c'est en général
 *     SA photo emblématique (l'église, la mairie, la vue d'ensemble) ;
 *  2. à défaut, géo-recherche sur Wikimedia Commons autour des coordonnées
 *     (meilleur effort : ce que la communauté a photographié à 300 m).
 * Cache durable. Null si rien de photogénique.
 */
class PlacePhotoService
{
    private const COMMONS_ENDPOINT = 'https://commons.wikimedia.org/w/api.php';

    private const WIKIPEDIA_SUMMARY = 'https://fr.wikipedia.org/api/rest_v1/page/summary/';

    private const HIT_TTL_DAYS = 365;
    private const MISS_TTL_DAYS = 14;

    /** Images d'article à ne pas prendre pour une photo : cartes, drapeaux, blasons… */
    private const REJECTED_IMAGE_WORDS = ['map', 'carte', 'flag', 'drapeau', 'blason', 'coat', 'arms', 'logo', 'locator', 'location', '.svg'];

    /**
     * Vrai si photoFor() répondra depuis le cache (Wikipédia et géo-recherche
     * déjà tentés), sans appel réseau.
     */
    public function isResolved(float $latitude, float $longitude, ?string $place = null): bool
    {
        if ($place !== null) {
            $locality = trim(explode(',', $place)[0]);
            if (mb_strlen($locality) >= 3) {
                $wp = Cache::get('placephoto:wp:'.md5(mb_strtolower($locality)));
                if ($wp === null) {
                    return false;
                }
                if ($wp !== 'miss') {
                    return true;
                }
            }
        }

        return Cache::has(sprintf('placephoto:%.4f,%.4f', $latitude, $longitude));
    }

    public function photoFor(float $latitude, float $longitude, ?string $place = null): ?string
    {
        if ($place !== null && ($url = $this->wikipediaPhotoFor($place)) !== null) {
            return $url;
        }

        $key = sprintf('placephoto:%.4f,%.4f', $latitude, $longitude);

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $url = $this->fetchNearby($latitude, $longitude);
        Cache::put($key, $url ?? 'miss', now()->addDays($url ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));

        return $url;
    }

    /**
     * Image de l'article Wikipédia de la localité : premier segment du lieu
     * (« Agonac, Dordogne, France » → « Agonac »).
     */
    private function wikipediaPhotoFor(string $place): ?string
    {
        $locality = trim(explode(',', $place)[0]);
        if (mb_strlen($locality) < 3) {
            return null;
        }

        $key = 'placephoto:wp:'.md5(mb_strtolower($locality));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $url = $this->fetchWikipedia($locality);
        Cache::put($key, $url ?? 'miss', now()->addDays($url ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));

        return $url;
    }

    private function fetchWikipedia(string $locality): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
            ])->timeout(6)->get(self::WIKIPEDIA_SUMMARY.rawurlencode(str_replace(' ', '_', $locality)));

            // Seuls les vrais articles comptent (pas les pages d'homonymie).
            if (! $response->ok() || $response->json('type') !== 'standard') {
                return null;
            }

            $url = $response->json('originalimage.source');
            if (! is_string($url) || $url === '') {
                return null;
            }

            $name = mb_strtolower(rawurldecode($url));
            foreach (self::REJECTED_IMAGE_WORDS as $word) {
                if (str_contains($name, $word)) {
                    return null;
                }
            }

            // Variante réduite (1280 px) de l'original, via le dépôt Commons.
            return $this->commonsThumb($url, 1280);
        } catch (\Throwable $e) {
            Log::warning('PlacePhotoService: échec Wikipédia', ['locality' => $locality, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * URL de vignette Commons à partir de l'URL d'un original
     * (…/commons/a/ab/Nom.jpg → …/commons/thumb/a/ab/Nom.jpg/1280px-Nom.jpg).
     */
    private function commonsThumb(string $originalUrl, int $width): string
    {
        $path = parse_url($originalUrl, PHP_URL_PATH) ?? '';
        if (! preg_match('#^/wikipedia/commons/([0-9a-f])/([0-9a-f]{2})/([^/]+)$#', $path, $m)) {
            return $originalUrl;
        }

        return "https://upload.wikimedia.org/wikipedia/commons/thumb/{$m[1]}/{$m[2]}/{$m[3]}/{$width}px-{$m[3]}";
    }

    private function fetchNearby(float $latitude, float $longitude): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
            ])->timeout(6)->get(self::COMMONS_ENDPOINT, [
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
