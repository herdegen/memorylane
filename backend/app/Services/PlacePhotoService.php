<?php

namespace App\Services;

use App\Support\ExternalHttp;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Photo d'illustration d'un lieu, trouvée automatiquement, de la source la
 * plus sûre à la plus hasardeuse :
 *  1. l'« image principale » Wikidata (P18) de la localité nommée — une vraie
 *     vue (la skyline de Denver, la Grand-Place de Lille) là où l'article
 *     Wikipédia montre un drapeau ou un collage ; les homonymes sont
 *     départagés par la distance au lieu géocodé ;
 *  2. celle de la commune qui contient le point, d'après Nominatim, si son
 *     nom est celui du lieu (« Trèves » → Trier, une adresse → sa ville) ;
 *  3. l'image de l'article Wikipédia (fr) de la localité, s'il parle bien
 *     de ce lieu-là (coordonnées proches) ;
 *  4. une photo de Commons prise à 300 m, si son nom mentionne le lieu
 *     (sinon c'est n'importe quoi : un bus, un portrait, une remise de prix) ;
 *  5. l'image principale de la commune qui contient le point, même si son
 *     nom diffère (Rotenfels → Gaggenau, dont c'est un quartier).
 * Le résultat final est gardé en cache ; un échec passager (réseau, 429)
 * ne l'est pas, on retente à la frise suivante. Null si rien de photogénique.
 */
class PlacePhotoService
{
    private const COMMONS_ENDPOINT = 'https://commons.wikimedia.org/w/api.php';

    private const WIKIPEDIA_SUMMARY = 'https://fr.wikipedia.org/api/rest_v1/page/summary/';

    private const WIKIDATA_ENDPOINT = 'https://www.wikidata.org/w/api.php';

    /** Au-delà, la localité trouvée par son nom est un homonyme, pas notre lieu. */
    private const MAX_KM = 25;

    private const HIT_TTL_DAYS = 365;
    private const MISS_TTL_DAYS = 14;

    /** Fichiers à ne pas prendre pour une photo (mots entiers du nom). */
    private const REJECTED_WORDS = [
        'map', 'carte', 'karte', 'flag', 'drapeau', 'flagge', 'blason', 'coat', 'arms', 'wappen', 'logo',
        'locator', 'location', 'plaque', 'panneau', 'dop20', 'dop', 'thumbnail', 'svg', 'tif', 'tiff',
    ];

    /** Mots trop courants pour prouver qu'une photo parle du lieu. */
    private const GENERIC_WORDS = [
        'saint', 'sainte', 'sankt', 'sint', 'rue', 'avenue', 'boulevard', 'place', 'chemin', 'route', 'quai',
        'grand', 'grande', 'stadt', 'ville', 'city', 'france', 'belgique', 'allemagne', 'espagne', 'suisse',
        'autriche', 'portugal', 'italie', 'cathedrale', 'eglise',
    ];

    private const COUNTRY_SUFFIX = '/\s+(?:en\s+)?(?:france|belgique|allemagne|espagne|portugal|suisse|autriche|italie|luxembourg|pays-bas)$/iu';

    private const STREET = '/^\d|\b(?:rue|avenue|av\.|boulevard|bd|place|chemin|allée|allee|impasse|quai|route|square)\b/iu';

    public function __construct(private GeocodeService $geocoder) {}

    /**
     * Nom de la localité dans un lieu saisi : premier segment, sans code
     * postal, arrondissement, précision entre parenthèses ni pays collé ;
     * la ville plutôt que la rue pour une adresse.
     * « 66 rue de Paris, 93100 Montreuil » → « Montreuil » ;
     * « BRUGES      Belgique » → « Bruges ».
     */
    public static function locality(string $place): string
    {
        $bare = preg_replace('/\s*\([^)]*\)/u', '', GeocodeService::cleanPlace($place));
        $segments = array_values(array_filter(array_map('trim', explode(',', $bare)), fn ($s) => $s !== '' && ! preg_match('/^[\d\s]+$/u', $s)));
        $segment = $segments[0] ?? '';
        if (preg_match(self::STREET, $segment)) {
            if (isset($segments[1])) {
                $segment = $segments[1];
            } elseif (preg_match('/\b\d{5}\s+(.+)$/u', $segment, $m)) {
                $segment = $m[1];
            }
        }
        $city = preg_replace(self::COUNTRY_SUFFIX, '', GeocodeService::bareCity($segment));
        $city = trim(preg_replace('/\s+/u', ' ', $city));

        return $city !== '' && mb_strtoupper($city) === $city ? mb_convert_case(mb_strtolower($city), MB_CASE_TITLE) : $city;
    }

    /** Clé du résultat final : la localité ET sa position (homonymes). */
    private function key(?string $locality, float $latitude, float $longitude): string
    {
        return 'placephoto:v3:'.md5(mb_strtolower((string) $locality)).sprintf(':%.2f,%.2f', $latitude, $longitude);
    }

    /** Localité utilisable d'un lieu saisi (null si trop courte ou absente). */
    private function localityOf(?string $place): ?string
    {
        $locality = $place !== null ? self::locality($place) : null;

        return $locality !== null && mb_strlen($locality) >= 3 ? $locality : null;
    }

    /** Vrai si photoFor() répondra depuis le cache, sans appel réseau. */
    public function isResolved(float $latitude, float $longitude, ?string $place = null): bool
    {
        return Cache::has($this->key($this->localityOf($place), $latitude, $longitude));
    }

    public function photoFor(float $latitude, float $longitude, ?string $place = null): ?string
    {
        $locality = $this->localityOf($place);
        $key = $this->key($locality, $latitude, $longitude);
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $url = $this->resolve($locality, $latitude, $longitude);
        if ($url === false) {
            return null; // échec passager : on retentera à la prochaine frise
        }
        Cache::put($key, $url ?? 'miss', now()->addDays($url ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));

        return $url;
    }

    /** Les cinq sources dans l'ordre ; false dès qu'une est injoignable. */
    private function resolve(?string $locality, float $latitude, float $longitude): string|false|null
    {
        if ($locality !== null) {
            $url = $this->byTitle($locality, $latitude, $longitude);
            if ($url !== null) {
                return $url;
            }
        }

        $municipality = $this->geocoder->municipalityAt($latitude, $longitude);
        if ($municipality === false) {
            return false;
        }
        $sameName = $municipality && ($locality === null || $this->sameName($municipality['names'], $locality));
        if ($sameName && ($url = $this->byWikidataId($municipality['wikidata'])) !== null) {
            return $url;
        }

        if ($locality !== null && ($url = $this->fetchWikipedia($locality, $latitude, $longitude)) !== null) {
            return $url;
        }

        $names = array_merge($locality !== null ? [$locality] : [], $municipality['names'] ?? []);
        if ($names !== [] && ($url = $this->fetchNearby($latitude, $longitude, $names)) !== null) {
            return $url;
        }

        return $municipality && ! $sameName ? $this->byWikidataId($municipality['wikidata']) : null;
    }

    /**
     * Localités de ce nom sur Wikipédia (fr, en, de) → l'entité Wikidata la
     * plus proche du lieu géocodé (pages d'homonymie, sans coordonnées, et
     * homonymes lointains écartés) → son image principale.
     */
    private function byTitle(string $locality, float $latitude, float $longitude): string|false|null
    {
        $response = $this->get(self::WIKIDATA_ENDPOINT, [
            'action' => 'wbgetentities',
            'sites' => 'frwiki|enwiki|dewiki',
            'titles' => $locality,
            'props' => 'claims',
            'format' => 'json',
        ]);
        if (! $response instanceof Response) {
            return $response;
        }

        $file = null;
        $best = self::MAX_KM;
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

        return $file !== null ? $this->commonsImage($file) : null;
    }

    /** Image principale d'une entité Wikidata connue (Q…), en cache. */
    private function byWikidataId(string $id): string|false|null
    {
        $key = 'placephoto:qid:'.$id;
        $cached = Cache::get($key);
        if ($cached !== null) {
            return $cached === 'miss' ? null : $cached;
        }

        $response = $this->get(self::WIKIDATA_ENDPOINT, ['action' => 'wbgetentities', 'ids' => $id, 'props' => 'claims', 'format' => 'json']);
        if (! $response instanceof Response) {
            return $response;
        }
        $image = $this->mainImage($response->json("entities.{$id}.claims.P18") ?? []);
        $url = $image !== null ? $this->commonsImage($image) : null;
        if ($url !== false) {
            Cache::put($key, $url ?? 'miss', now()->addDays($url ? self::HIT_TTL_DAYS : self::MISS_TTL_DAYS));
        }

        return $url;
    }

    /** Vignette de 1280 px (ou l'original s'il est plus petit) d'un fichier Commons. */
    private function commonsImage(string $file): string|false|null
    {
        if ($this->rejected($file)) {
            return null;
        }
        $response = $this->get(self::COMMONS_ENDPOINT, [
            'action' => 'query',
            'format' => 'json',
            'titles' => 'File:'.$file,
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime',
            'iiurlwidth' => 1280,
        ]);
        if (! $response instanceof Response) {
            return $response;
        }
        $info = collect($response->json('query.pages') ?? [])->first()['imageinfo'][0] ?? null;
        if (! $info || ! str_starts_with($info['mime'] ?? '', 'image/') || str_contains($info['mime'], 'svg')) {
            return null;
        }

        return strtok($info['thumburl'] ?? $info['url'], '?') ?: null;
    }

    /** Image de l'article Wikipédia de la localité, s'il s'agit bien de ce lieu. */
    private function fetchWikipedia(string $locality, float $latitude, float $longitude): string|false|null
    {
        $response = $this->get(self::WIKIPEDIA_SUMMARY.rawurlencode(str_replace(' ', '_', $locality)));
        if (! $response instanceof Response) {
            return $response;
        }
        // Seuls les vrais articles comptent (pas les pages d'homonymie)…
        if ($response->json('type') !== 'standard') {
            return null;
        }
        // … et pas l'article d'un homonyme lointain.
        $lat = $response->json('coordinates.lat');
        $lon = $response->json('coordinates.lon');
        if (is_numeric($lat) && is_numeric($lon) && $this->distanceKm($latitude, $longitude, (float) $lat, (float) $lon) > self::MAX_KM) {
            return null;
        }

        $url = $response->json('originalimage.source');
        if (! is_string($url) || $url === '' || $this->rejected(basename(parse_url($url, PHP_URL_PATH) ?? ''))) {
            return null;
        }
        // Variante réduite (1280 px) via le dépôt Commons, sauf si
        // l'original est déjà plus petit.
        $width = (int) $response->json('originalimage.width');

        return $width > 0 && $width <= 1280 ? strtok($url, '?') : $this->commonsThumb($url, 1280);
    }

    /**
     * Photo de Commons prise à 300 m dont le nom mentionne le lieu.
     *
     * @param  list<string>  $names
     */
    private function fetchNearby(float $latitude, float $longitude, array $names): string|false|null
    {
        $response = $this->get(self::COMMONS_ENDPOINT, [
            'action' => 'query',
            'format' => 'json',
            'generator' => 'geosearch',
            'ggscoord' => "{$latitude}|{$longitude}",
            'ggsradius' => 300,
            'ggslimit' => 20,
            'ggsnamespace' => 6, // fichiers
            'prop' => 'imageinfo',
            'iiprop' => 'url|mime',
            'iiurlwidth' => 1280,
        ]);
        if (! $response instanceof Response) {
            return $response;
        }

        $wanted = collect($names)->flatMap(fn ($n) => $this->words($n))
            ->reject(fn ($w) => mb_strlen($w) < 4 || in_array($w, self::GENERIC_WORDS, true))
            ->unique()->all();
        foreach ($response->json('query.pages') ?? [] as $page) {
            $info = $page['imageinfo'][0] ?? null;
            $title = (string) ($page['title'] ?? '');
            if (! $info || ! str_starts_with($info['mime'] ?? '', 'image/') || str_contains($info['mime'], 'svg') || $this->rejected($title)) {
                continue;
            }
            if (array_intersect($wanted, $this->words($title)) !== []) {
                return strtok($info['thumburl'] ?? $info['url'], '?') ?: null;
            }
        }

        return null;
    }

    /**
     * GET vers un service Wikimedia : la réponse si elle est définitive
     * (trouvé ou absent), null si absent, false si échec passager (trop de
     * requêtes, panne, réseau) à ne pas mémoriser.
     */
    private function get(string $url, array $query = []): Response|false|null
    {
        try {
            $response = Http::withHeaders(['User-Agent' => ExternalHttp::userAgent()])->timeout(6)->get($url, $query);
        } catch (\Throwable $e) {
            Log::warning('PlacePhotoService: échec Wikimedia', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }
        if (! $response->successful() && ! in_array($response->status(), [400, 403, 404], true)) {
            Log::warning('PlacePhotoService: Wikimedia indisponible', ['url' => $url, 'status' => $response->status()]);

            return false;
        }

        return $response->ok() ? $response : null;
    }

    /** Fichier de l'image principale : rang « préféré » d'abord, jamais « déprécié ». */
    private function mainImage(array $claims): ?string
    {
        $claims = array_values(array_filter($claims, fn ($c) => ($c['rank'] ?? 'normal') !== 'deprecated'));
        usort($claims, fn ($a, $b) => (($b['rank'] ?? '') === 'preferred') <=> (($a['rank'] ?? '') === 'preferred'));
        $file = data_get($claims, '0.mainsnak.datavalue.value');

        return is_string($file) && $file !== '' ? $file : null;
    }

    /** Mots d'un nom, en minuscules sans accents (« Saint-Étienne » → saint, etienne). */
    private function words(string $text): array
    {
        $text = Str::ascii(mb_strtolower(rawurldecode($text)));
        $text = preg_replace('/\b(st|ste)\b/', 'saint', $text);

        return array_values(array_filter(preg_split('/[^a-z0-9]+/', $text)));
    }

    /** Vrai si le nom de la commune est celui du lieu (« Wien » dans « Wien Stadt »). */
    private function sameName(array $names, string $locality): bool
    {
        $place = ' '.implode(' ', $this->words($locality)).' ';
        foreach ($names as $name) {
            $candidate = implode(' ', $this->words($name));
            if ($candidate !== '' && (str_contains($place, " {$candidate} ") || str_contains(" {$candidate} ", $place))) {
                return true;
            }
        }

        return false;
    }

    /** Vrai pour une carte, un drapeau, un blason… (mots entiers du nom de fichier). */
    private function rejected(string $name): bool
    {
        return array_intersect(self::REJECTED_WORDS, $this->words($name)) !== [];
    }

    private function distanceKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $a = sin(deg2rad($lat2 - $lat1) / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin(deg2rad($lng2 - $lng1) / 2) ** 2;

        return 2 * 6371 * asin(min(1, sqrt($a)));
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
}
