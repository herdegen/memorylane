<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Géocodage des lieux en texte libre (« Croix, Nord », « Église Saint-Pierre,
 * Lyon ») via Nominatim (OpenStreetMap), avec cache durable : un lieu donné
 * n'est résolu qu'une fois. Sert au récit de vie (déplacements sur carte).
 */
class GeocodeService
{
    private const ENDPOINT = 'https://nominatim.openstreetmap.org/search';

    private const REVERSE_ENDPOINT = 'https://nominatim.openstreetmap.org/reverse';

    /** API Adresse de l'État (BAN) : adresses françaises, sans clé, rapide. */
    private const BAN_ENDPOINT = 'https://api-adresse.data.gouv.fr/search/';

    /** Un vrai résultat ne bouge plus ; un échec est retenté après 7 jours. */
    private const HIT_TTL_DAYS = 365;
    private const MISS_TTL_DAYS = 7;

    /** Politesse Nominatim : au plus une requête réseau par seconde. */
    private static float $lastFetchAt = 0.0;

    /**
     * Vrai si le lieu a déjà été résolu (hit ou raté) : coordinatesFor() ne
     * fera alors aucun appel réseau.
     */
    public function isResolved(?string $place): bool
    {
        $place = trim((string) $place);

        return mb_strlen($place) < 3 || Cache::has('geocode:' . md5(mb_strtolower($place)));
    }

    /**
     * Coordonnées d'un lieu, ou null si introuvable / source injoignable.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function coordinatesFor(?string $place): ?array
    {
        $place = trim((string) $place);
        if ($place === '' || mb_strlen($place) < 3) {
            return null;
        }

        $key = 'geocode:' . md5(mb_strtolower($place));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $result = $this->fetch($place);
        Cache::put(
            $key,
            $result ?? 'miss',
            now()->addDays($result ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS),
        );

        return $result;
    }

    /**
     * Géocode une adresse postale française via la BAN (adresse.data.gouv.fr).
     * Renvoie aussi la ville, utile en affichage de repli. Même politique de
     * cache que coordinatesFor ; pas de throttle (la BAN tolère ~50 req/s).
     *
     * @return array{latitude: float, longitude: float, city: ?string}|null
     */
    public function addressFor(?string $address): ?array
    {
        $address = trim((string) $address);
        if ($address === '' || mb_strlen($address) < 3) {
            return null;
        }

        $key = 'geocode:ban:' . md5(mb_strtolower($address));

        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $result = $this->fetchBan($address);

        // Panne du service (réseau, 5xx) : on ne retient l'échec qu'une heure,
        // pour réessayer vite ; une adresse réellement introuvable, plus longtemps.
        if ($result === false) {
            Cache::put($key, 'miss', now()->addHour());

            return null;
        }

        Cache::put(
            $key,
            $result ?? 'miss',
            now()->addDays($result ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS),
        );

        return $result;
    }

    /**
     * @return array{latitude: float, longitude: float, city: ?string}|null|false
     *         null = introuvable ; false = service indisponible
     */
    private function fetchBan(string $address): array|null|false
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
            ])->timeout(6)->get(self::BAN_ENDPOINT, [
                'q' => $address,
                'limit' => 1,
            ]);

            if (! $response->ok()) {
                return false;
            }
            $feature = $response->json('features')[0] ?? null;
            // GeoJSON : coordinates = [longitude, latitude].
            $coords = $feature['geometry']['coordinates'] ?? null;
            if (! is_array($coords) || count($coords) < 2) {
                return null;
            }

            return [
                'latitude' => (float) $coords[1],
                'longitude' => (float) $coords[0],
                'city' => $feature['properties']['city'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::warning('GeocodeService: échec BAN', ['address' => $address, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Nom lisible d'un endroit (« Saint-Malo », « Barcelone, Espagne ») d'après
     * ses coordonnées : cache seulement, sans appel réseau (null si inconnu
     * ou pas encore résolu — voir ResolvePlaceNames).
     */
    public function cachedPlaceName(float $lat, float $lng): ?string
    {
        $cached = Cache::get($this->reverseKey($lat, $lng));

        return is_string($cached) && $cached !== 'miss' ? $cached : null;
    }

    public function isPlaceNameResolved(float $lat, float $lng): bool
    {
        return Cache::has($this->reverseKey($lat, $lng));
    }

    /** Nom lisible d'un endroit, résolu par Nominatim (géocodage inverse) si besoin. */
    public function placeNameFor(float $lat, float $lng): ?string
    {
        $key = $this->reverseKey($lat, $lng);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $name = $this->fetchReverse($lat, $lng);
        Cache::put($key, $name ?? 'miss', now()->addDays($name ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));

        return $name;
    }

    /** Cellule d'environ 1 km : des points voisins partagent leur nom. */
    private function reverseKey(float $lat, float $lng): string
    {
        return 'geocode:rev:'.round($lat, 2).','.round($lng, 2);
    }

    private function fetchReverse(float $lat, float $lng): ?string
    {
        try {
            $elapsed = microtime(true) - self::$lastFetchAt;
            if ($elapsed < 1.1) {
                usleep((int) ((1.1 - $elapsed) * 1_000_000));
            }
            self::$lastFetchAt = microtime(true);

            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
                'Accept-Language' => 'fr',
            ])->timeout(6)->get(self::REVERSE_ENDPOINT, [
                'lat' => $lat,
                'lon' => $lng,
                'format' => 'json',
                'zoom' => 10,
                'addressdetails' => 1,
            ]);

            $a = $response->ok() ? ($response->json('address') ?? null) : null;
            if (! is_array($a)) {
                return null;
            }
            $name = $a['city'] ?? $a['town'] ?? $a['village'] ?? $a['municipality'] ?? $a['county'] ?? $a['state'] ?? null;
            if (! $name) {
                return $a['country'] ?? null;
            }
            $country = $a['country_code'] ?? null;

            return $country && $country !== 'fr' && ! empty($a['country']) ? "{$name}, {$a['country']}" : $name;
        } catch (\Throwable $e) {
            Log::warning('GeocodeService: échec Nominatim inverse', ['lat' => $lat, 'lng' => $lng, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array{latitude: float, longitude: float}|null
     */
    private function fetch(string $place): ?array
    {
        try {
            // Rythme max 1 req/s (politique d'usage Nominatim).
            $elapsed = microtime(true) - self::$lastFetchAt;
            if ($elapsed < 1.1) {
                usleep((int) ((1.1 - $elapsed) * 1_000_000));
            }
            self::$lastFetchAt = microtime(true);

            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
            ])->timeout(6)->get(self::ENDPOINT, [
                'q' => $place,
                'format' => 'json',
                'limit' => 1,
            ]);

            $first = $response->ok() ? ($response->json()[0] ?? null) : null;
            if (! $first || ! isset($first['lat'], $first['lon'])) {
                return null;
            }

            return [
                'latitude' => (float) $first['lat'],
                'longitude' => (float) $first['lon'],
            ];
        } catch (\Throwable $e) {
            Log::warning('GeocodeService: échec Nominatim', ['place' => $place, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
