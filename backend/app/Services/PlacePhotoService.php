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
            return $cached === 'miss' ? null : $this->downsized($cached);
        }

        $url = $this->fetchNearby($latitude, $longitude);
        if ($url === false) {
            return null; // échec passager : on retentera à la prochaine frise
        }
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
            return $cached === 'miss' ? null : $this->downsized($cached);
        }

        $url = $this->fetchWikipedia($locality);
        if ($url === false) {
            return null; // échec passager : on retentera à la prochaine frise
        }
        Cache::put($key, $url ?? 'miss', now()->addDays($url ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));

        return $url;
    }

    /**
     * Vrai si la réponse est définitive (article trouvé ou absent) ; faux
     * pour un refus passager (trop de requêtes, panne) à ne pas mémoriser.
     */
    private function definitive(\Illuminate\Http\Client\Response $response): bool
    {
        return $response->successful() || in_array($response->status(), [400, 403, 404], true);
    }

    /** URL de l'image, null si l'article n'en a pas de photogénique, false si échec passager. */
    private function fetchWikipedia(string $locality): string|false|null
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
            ])->timeout(6)->get(self::WIKIPEDIA_SUMMARY.rawurlencode(str_replace(' ', '_', $locality)));

            if (! $this->definitive($response)) {
                Log::warning('PlacePhotoService: Wikipédia indisponible', ['locality' => $locality, 'status' => $response->status()]);

                return false;
            }
            // Seuls les vrais articles comptent (pas les pages d'homonymie).
            if (! $response->ok() || $response->json('type') !== 'standard') {
                return null;
            }

            $url = $response->json('originalimage.source');
            if (! is_string($url) || $url === '') {
                return null;
            }

            // Nom du fichier seul : l'URL porte des paramètres de suivi.
            $name = mb_strtolower(rawurldecode(basename(parse_url($url, PHP_URL_PATH) ?? '')));
            foreach (self::REJECTED_IMAGE_WORDS as $word) {
                if (str_contains($name, $word)) {
                    return null;
                }
            }

            // Variante réduite (1280 px) via le dépôt Commons, sauf si
            // l'original est déjà plus petit.
            $width = (int) $response->json('originalimage.width');

            return $width > 0 && $width <= 1280
                ? strtok($url, '?')
                : $this->commonsThumb($url, 1280);
        } catch (\Throwable $e) {
            Log::warning('PlacePhotoService: échec Wikipédia', ['locality' => $locality, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * URL de vignette Commons à partir de l'URL d'un original
     * (…/commons/a/ab/Nom.jpg → …/commons/thumb/a/ab/Nom.jpg/1280px-Nom.jpg)
     * ou d'une vignette d'une autre taille (Wikipédia renvoie parfois
     * thumb.wikimedia.org/…/3840px-Nom.jpg).
     */
    private function commonsThumb(string $url, int $width): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        if (! preg_match('#^/wikipedia/commons/(?:thumb/)?([0-9a-f])/([0-9a-f]{2})/([^/]+?)(?:/\d+px-[^/]+)?$#', $path, $m)) {
            return $url;
        }

        return "https://upload.wikimedia.org/wikipedia/commons/thumb/{$m[1]}/{$m[2]}/{$m[3]}/{$width}px-{$m[3]}";
    }

    /** Vignette déjà en cache plus large que 1280 px : ramenée à 1280. */
    private function downsized(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        return preg_match('#/thumb/.+/(\d+)px-[^/]+$#', $path, $m) && (int) $m[1] > 1280
            ? $this->commonsThumb($url, 1280)
            : $url;
    }

    /** URL de la photo voisine, null s'il n'y en a pas, false si échec passager. */
    private function fetchNearby(float $latitude, float $longitude): string|false|null
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

            if (! $this->definitive($response)) {
                Log::warning('PlacePhotoService: Commons indisponible', ['status' => $response->status()]);

                return false;
            }
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

            return false;
        }
    }
}
