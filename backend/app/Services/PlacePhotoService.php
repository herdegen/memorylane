<?php

namespace App\Services;

use App\Support\ExternalHttp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Photo d'illustration d'un lieu, trouvée automatiquement :
 *  1. l'« image principale » de la localité sur Wikidata (P18), choisie par
 *     la communauté : une vraie vue (la skyline de Denver, la Grand-Place de
 *     Lille) là où l'article Wikipédia montre un drapeau ou un collage. Les
 *     homonymes sont départagés par la distance au lieu géocodé ;
 *  2. à défaut, l'image de l'article Wikipédia (fr) de la localité ;
 *  3. à défaut, géo-recherche sur Wikimedia Commons autour des coordonnées
 *     (meilleur effort : ce que la communauté a photographié à 300 m).
 * Cache durable. Null si rien de photogénique.
 */
class PlacePhotoService
{
    private const COMMONS_ENDPOINT = 'https://commons.wikimedia.org/w/api.php';

    private const WIKIPEDIA_SUMMARY = 'https://fr.wikipedia.org/api/rest_v1/page/summary/';

    private const WIKIDATA_ENDPOINT = 'https://www.wikidata.org/w/api.php';

    /** Au-delà, la localité Wikidata est un homonyme, pas notre lieu. */
    private const WIKIDATA_MAX_KM = 25;

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
                $wd = Cache::get($this->wikidataKey($locality, $latitude, $longitude));
                if ($wd === null) {
                    return false;
                }
                if ($wd !== 'miss') {
                    return true;
                }
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
        if ($place !== null && ($url = $this->wikidataPhotoFor($place, $latitude, $longitude)) !== null) {
            return $url;
        }
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

    /** Clé de cache Wikidata : la localité ET sa position (homonymes). */
    private function wikidataKey(string $locality, float $latitude, float $longitude): string
    {
        return 'placephoto:wd:'.md5(mb_strtolower($locality)).sprintf(':%.2f,%.2f', $latitude, $longitude);
    }

    /** Image principale Wikidata de la localité (premier segment du lieu). */
    private function wikidataPhotoFor(string $place, float $latitude, float $longitude): ?string
    {
        $locality = trim(explode(',', $place)[0]);
        if (mb_strlen($locality) < 3) {
            return null;
        }

        $key = $this->wikidataKey($locality, $latitude, $longitude);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $this->downsized($cached);
        }

        $url = $this->fetchWikidata($locality, $latitude, $longitude);
        if ($url === false) {
            return null; // échec passager : on retentera à la prochaine frise
        }
        Cache::put($key, $url ?? 'miss', now()->addDays($url ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));

        return $url;
    }

    /**
     * Localités de ce nom sur Wikipédia (fr, en, de) → l'entité Wikidata la
     * plus proche du lieu géocodé (pages d'homonymie et homonymes lointains
     * écartés) → son image principale, en vignette de 1280 px.
     */
    private function fetchWikidata(string $locality, float $latitude, float $longitude): string|false|null
    {
        try {
            $response = Http::withHeaders(['User-Agent' => ExternalHttp::userAgent()])
                ->timeout(6)
                ->get(self::WIKIDATA_ENDPOINT, [
                    'action' => 'wbgetentities',
                    'sites' => 'frwiki|enwiki|dewiki',
                    'titles' => $locality,
                    'props' => 'claims',
                    'format' => 'json',
                ]);
            if (! $this->definitive($response)) {
                Log::warning('PlacePhotoService: Wikidata indisponible', ['locality' => $locality, 'status' => $response->status()]);

                return false;
            }
            if (! $response->ok()) {
                return null;
            }

            $file = null;
            $best = self::WIKIDATA_MAX_KM;
            foreach ($response->json('entities') ?? [] as $entity) {
                $coords = data_get($entity, 'claims.P625.0.mainsnak.datavalue.value');
                $image = $this->mainImage($entity['claims']['P18'] ?? []);
                if (! is_array($coords) || $image === null) {
                    continue;
                }
                $km = $this->distanceKm($latitude, $longitude, (float) $coords['latitude'], (float) $coords['longitude']);
                if ($km <= $best) {
                    $best = $km;
                    $file = $image;
                }
            }
            if ($file === null || $this->rejected($file)) {
                return null;
            }

            $info = Http::withHeaders(['User-Agent' => ExternalHttp::userAgent()])
                ->timeout(6)
                ->get(self::COMMONS_ENDPOINT, [
                    'action' => 'query',
                    'format' => 'json',
                    'titles' => 'File:'.$file,
                    'prop' => 'imageinfo',
                    'iiprop' => 'url|mime',
                    'iiurlwidth' => 1280,
                ]);
            if (! $this->definitive($info)) {
                return false;
            }
            $image = collect($info->json('query.pages') ?? [])->first()['imageinfo'][0] ?? null;
            if (! $image || ! str_starts_with($image['mime'] ?? '', 'image/') || str_contains($image['mime'], 'svg')) {
                return null;
            }

            return strtok($image['thumburl'] ?? $image['url'], '?') ?: null;
        } catch (\Throwable $e) {
            Log::warning('PlacePhotoService: échec Wikidata', ['locality' => $locality, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /** Fichier de l'image principale : rang « préféré » d'abord, jamais « déprécié ». */
    private function mainImage(array $claims): ?string
    {
        $claims = array_filter($claims, fn ($c) => ($c['rank'] ?? 'normal') !== 'deprecated');
        usort($claims, fn ($a, $b) => (($b['rank'] ?? '') === 'preferred') <=> (($a['rank'] ?? '') === 'preferred'));
        $file = data_get($claims, '0.mainsnak.datavalue.value');

        return is_string($file) && $file !== '' ? $file : null;
    }

    /** Vrai pour une carte, un drapeau, un blason… (d'après le nom du fichier). */
    private function rejected(string $name): bool
    {
        $name = mb_strtolower(rawurldecode($name));
        foreach (self::REJECTED_IMAGE_WORDS as $word) {
            if (str_contains($name, $word)) {
                return true;
            }
        }

        return false;
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * 6371 * asin(min(1, sqrt($a)));
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
                'User-Agent' => ExternalHttp::userAgent(),
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
            if ($this->rejected(basename(parse_url($url, PHP_URL_PATH) ?? ''))) {
                return null;
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
                'User-Agent' => ExternalHttp::userAgent(),
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
