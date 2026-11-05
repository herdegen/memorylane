<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Géocodage des lieux en texte libre (« Croix, Nord », « Église Saint-Pierre,
 * Lyon ») via Nominatim (OpenStreetMap), avec cache durable : un lieu donné
 * n'est résolu qu'une fois. Sert au récit de vie (déplacements sur carte) et
 * à « la famille dans le temps » (carte), qui ne lisent que le cache : les
 * appels réseau partent en tâche de fond (EnrichTimelinePlaces,
 * people:geocode-places).
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

    /** Panne de Nominatim (réseau, 5xx, refus) : on réessaie dans l'heure. */
    private const DOWN_TTL_HOURS = 1;

    /** Politesse Nominatim : au plus une requête réseau par seconde. */
    private static float $lastFetchAt = 0.0;

    /** Clé de cache d'un lieu en texte (insensible à la casse). */
    public static function placeKey(string $place): string
    {
        return 'geocode:' . md5(mb_strtolower(trim($place)));
    }

    /**
     * Libellé de lieu nettoyé : les lieux GEDCOM du type « Croix, , , , »
     * perdent leurs virgules vides. Même forme partout, donc même clé de cache.
     */
    public static function cleanPlace(?string $place): string
    {
        return trim(preg_replace('/(\s*,\s*)+/', ', ', (string) $place), " \t,");
    }

    /**
     * Vrai si le lieu a déjà été résolu (hit ou raté) : coordinatesFor() ne
     * fera alors aucun appel réseau.
     */
    public function isResolved(?string $place): bool
    {
        $place = trim((string) $place);

        return mb_strlen($place) < 3 || Cache::has(self::placeKey($place));
    }

    /**
     * Coordonnées de plusieurs lieux d'après le cache SEUL (aucun appel
     * réseau) : [lieu => coordonnées]. Les lieux introuvables ou pas encore
     * résolus sont absents ; ceux qui restent à résoudre sont ajoutés à
     * $pending (les ratés connus n'y sont pas).
     *
     * @param  iterable<string>  $places
     * @param  array<int, string>|null  $pending
     * @return array<string, array{latitude: float, longitude: float}>
     */
    public function cachedCoordinatesMany(iterable $places, ?array &$pending = null): array
    {
        // Une clé peut servir à plusieurs saisies (« Paris » et « paris »).
        $keys = [];
        foreach ($places as $place) {
            $place = trim((string) $place);
            if (mb_strlen($place) >= 3) {
                $keys[self::placeKey($place)][$place] = true;
            }
        }
        $pending = [];
        if ($keys === []) {
            return [];
        }

        $found = [];
        foreach (Cache::many(array_keys($keys)) as $key => $value) {
            if (is_array($value)) {
                foreach (array_keys($keys[$key]) as $place) {
                    $found[$place] = $value;
                }
            } elseif ($value === null) {
                $pending[] = (string) array_key_first($keys[$key]);
            }
        }

        return $found;
    }

    /**
     * Coordonnées d'un lieu, ou null si introuvable / source injoignable.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public function coordinatesFor(?string $place): ?array
    {
        $result = $this->lookup($place);

        return is_array($result) ? $result : null;
    }

    /**
     * Comme coordinatesFor(), mais distingue « introuvable » (null, mémorisé
     * MISS_TTL_DAYS jours : pas de nouvel essai à chaque passage) de
     * « Nominatim injoignable » (false, mémorisé une heure seulement).
     *
     * Les saisies sont variées (« Gournay-sur-Marne, 93033, Seine-Saint-Denis,
     * Ile-de-France, France », « Paris 10e arrondissement, France ») : un lieu
     * introuvable tel quel est retenté sous des formes simplifiées (sans les
     * codes, puis « ville, pays ») avant d'abandonner.
     *
     * @param  bool  $retryMiss  retenter aussi un lieu déjà noté introuvable
     * @return array{latitude: float, longitude: float}|null|false
     */
    public function lookup(?string $place, bool $retryMiss = false): array|null|false
    {
        $place = trim((string) $place);
        if (mb_strlen($place) < 3) {
            return null;
        }

        $key = self::placeKey($place);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($cached !== null && ! $retryMiss) {
            return null;
        }

        // Adresse française avec numéro et rue (« 66 rue de Paris, Montreuil ») :
        // la BAN connaît les rues et départage les homonymes (Montreuil-sous-
        // Bois et non Montreuil-sur-Mer, choisi par Nominatim) ; Nominatim
        // reste le secours.
        if (self::looksLikeFrenchStreet($place)) {
            $ban = $this->fetchBan($place);
            if (is_array($ban) && ($ban['score'] ?? 0) >= self::BAN_MIN_SCORE) {
                $this->remember($key, $found = ['latitude' => $ban['latitude'], 'longitude' => $ban['longitude']]);

                return $found;
            }
        }

        $result = null;
        foreach (self::placeVariants($place) as $i => $variant) {
            // Forme simplifiée déjà connue (autre saisie du même lieu) : pas d'appel.
            $known = $i > 0 ? Cache::get(self::placeKey($variant)) : null;
            if (is_array($known)) {
                $result = $known;
                break;
            }
            if ($known !== null && ! $retryMiss) {
                continue;
            }

            $result = $this->fetch($variant);
            if ($result === false) {
                Cache::put($key, 'miss', now()->addHours(self::DOWN_TTL_HOURS));

                return false;
            }
            if ($i > 0) {
                $this->remember(self::placeKey($variant), $result);
            }
            if ($result) {
                break;
            }
        }

        $this->remember($key, $result);

        return $result;
    }

    /** Un vrai résultat est gardé un an, un raté une semaine. */
    private function remember(string $key, ?array $result): void
    {
        Cache::put($key, $result ?? 'miss', now()->addDays($result ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));
    }

    /**
     * Formes d'un lieu à essayer, de la plus précise à la plus simple : tel
     * quel ; sans les segments numériques (codes postaux, INSEE) ; puis la
     * ville seule (sans code postal ni arrondissement) suivie du pays — le
     * dernier segment. Sans doublon.
     *
     * @return list<string>
     */
    public static function placeVariants(string $place): array
    {
        // Précisions saisies dans le lieu : « … à l'âge de 74 ans », « (Seinx) ».
        $bare = preg_replace(["/\s+à\s+l['’]âge\s.*$/iu", '/\s*\([^)]*\)/u'], '', $place);
        $parts = array_values(array_filter(array_map('trim', explode(',', $bare)), fn ($p) => $p !== ''));
        $named = array_values(array_filter($parts, fn ($p) => ! preg_match('/^[\d\s]+$/u', $p)));

        $variants = [trim($place), implode(', ', $named)];
        if ($named !== []) {
            $city = self::bareCity($named[0]);
            $variants[] = count($named) > 1 ? $city . ', ' . end($named) : $city;
        }

        $unique = [];
        foreach ($variants as $variant) {
            $variant = trim($variant, " \t,");
            if (mb_strlen($variant) >= 3) {
                $unique[mb_strtolower($variant)] ??= $variant;
            }
        }

        return array_values($unique);
    }

    /** « 75010 Paris » ou « Paris 10e arrondissement » → « Paris ». */
    private static function bareCity(string $segment): string
    {
        $city = preg_replace('/\b\d{4,5}\b/u', '', $segment);
        // Arrondissements : « Paris 10e », « Paris-Xème », « Lyon 3ème arr. ».
        $city = preg_replace('/[\s-]+(?:\d{1,2}|[IVXL]+)\s*(?:e|er|ème|eme|è)(?:\s.*)?$/iu', '', $city);
        $city = preg_replace('/\s+(?:arrondissement|arr\.?)(?:\s.*)?$/iu', '', $city);

        return trim(preg_replace('/\s+/u', ' ', $city), " \t-");
    }

    /**
     * Pause entre deux appels Nominatim (1 req/s au plus, politique d'usage),
     * ENTRE TOUS LES PROCESSUS : files d'attente, commande de nuit, frise —
     * l'heure du dernier appel est partagée dans le cache, sous verrou.
     */
    private function throttle(): void
    {
        $interval = (float) config('memorylane.nominatim_interval', 1.1);
        if ($interval <= 0) {
            return;
        }

        $wait = function () use ($interval) {
            $last = max(self::$lastFetchAt, (float) Cache::get('nominatim:last-call', 0));
            $delay = $last + $interval - microtime(true);
            if ($delay > 0) {
                usleep((int) ($delay * 1_000_000));
            }
            self::$lastFetchAt = microtime(true);
            Cache::put('nominatim:last-call', self::$lastFetchAt, 60);
        };

        try {
            Cache::lock('nominatim:throttle', 10)->block(30, $wait);
        } catch (\Throwable) {
            // Verrou indisponible : au moins la politesse de ce processus.
            $wait();
        }
    }

    /**
     * Géocode une adresse postale française via la BAN (adresse.data.gouv.fr).
     * Renvoie aussi la ville, utile en affichage de repli. Même politique de
     * cache que coordinatesFor ; pas de throttle (la BAN tolère ~50 req/s).
     *
     * @return array{latitude: float, longitude: float, city: ?string}|null
     */
    /** Score BAN en dessous duquel la réponse n'est pas l'adresse saisie. */
    private const BAN_MIN_SCORE = 0.5;

    /** Pays (en français, langue locale ou anglais) qui ne sont pas la France. */
    private const FOREIGN_PATTERN = '/\\b(espagne|españa|espana|spain|majorque|mallorca|baléares|baleares|italie|italia|italy|belgique|belgië|belgie|belgium|allemagne|deutschland|germany|suisse|schweiz|svizzera|switzerland|luxembourg|royaume[- ]uni|united kingdom|angleterre|england|écosse|scotland|irlande|ireland|portugal|pays[- ]bas|nederland|netherlands|autriche|österreich|austria|pologne|polska|poland|grèce|greece|maroc|morocco|algérie|algerie|tunisie|tunisia|canada|états[- ]unis|etats[- ]unis|usa|united states|brésil|brasil|brazil|argentine|argentina|mexique|mexico|méxico|australie|australia|japon|japan|chine|china|inde|india)\\b/iu';

    /** Numéro + voie (rue, avenue, boulevard…), en France. */
    public static function looksLikeFrenchStreet(string $place): bool
    {
        return ! self::looksForeign($place)
            && (bool) preg_match('/\\b\\d+\\s*(bis|ter)?\\s*,?\\s*(rue|avenue|av\\.?|boulevard|bd|chemin|place|allée|allee|impasse|quai|route|cours|square|villa|cité|cite|passage|sentier|résidence|residence)\\b/iu', $place);
    }

    public static function looksForeign(string $address): bool
    {
        return (bool) preg_match(self::FOREIGN_PATTERN, $address);
    }

    /**
     * Adresse hors de France (ou mal reconnue par la BAN) : Nominatim, ville
     * d'après le premier élément saisi.
     *
     * @return array{latitude: float, longitude: float, city: ?string}|null|false
     */
    private function worldAddress(string $address): array|null|false
    {
        // « Palma de Mallorca -España- » → « Palma de Mallorca, España ».
        $found = $this->lookup(preg_replace('/\\s*-\\s*([^-]+?)\\s*-\\s*$/u', ', $1', $address));
        if (! is_array($found)) {
            return $found;
        }
        $first = trim(preg_split('/[,(]|\\s-/u', $address)[0] ?? '');

        return [
            'latitude' => (float) $found['latitude'],
            'longitude' => (float) $found['longitude'],
            'city' => $first !== '' ? $first : null,
        ];
    }

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

        // La BAN ne connaît que la France : une adresse à l'étranger (ou
        // qu'elle ne reconnaît que vaguement) y tombe sur n'importe quelle
        // commune — « Palma de Mallorca -España- » était devenue Castelnou.
        // Ces adresses passent par Nominatim (monde entier).
        $result = self::looksForeign($address) ? $this->worldAddress($address) : $this->fetchBan($address);
        if (is_array($result) && ($result['score'] ?? 1.0) < self::BAN_MIN_SCORE) {
            $result = $this->worldAddress($address);
        }

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
                'score' => (float) ($feature['properties']['score'] ?? 1.0),
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
            $this->throttle();

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
     * @return array{latitude: float, longitude: float}|null|false
     *         null = introuvable ; false = service injoignable (réseau, 5xx, 429…)
     */
    private function fetch(string $place): array|null|false
    {
        try {
            // Rythme max 1 req/s (politique d'usage Nominatim).
            $this->throttle();

            $response = Http::withHeaders([
                'User-Agent' => 'MemoryLane/1.3.0 (family media hub)',
            ])->timeout(6)->get(self::ENDPOINT, [
                'q' => $place,
                'format' => 'json',
                'limit' => 1,
            ]);

            if (! $response->ok()) {
                Log::warning('GeocodeService: Nominatim indisponible', ['place' => $place, 'status' => $response->status()]);

                return false;
            }
            $first = $response->json()[0] ?? null;
            if (! is_array($first) || ! isset($first['lat'], $first['lon'])) {
                return null;
            }

            return [
                'latitude' => (float) $first['lat'],
                'longitude' => (float) $first['lon'],
            ];
        } catch (\Throwable $e) {
            Log::warning('GeocodeService: échec Nominatim', ['place' => $place, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
