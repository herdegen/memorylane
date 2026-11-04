<?php

namespace App\Services;

use App\Models\Person;
use Carbon\CarbonInterface;

/**
 * « La famille dans le temps » (carte) : pour chaque fiche, la suite des
 * lieux où elle a vécu — naissance, résidences datées, décès et, pour une
 * personne vivante, son adresse actuelle — réduite à des segments
 * { from, to, lat, lng } en années décimales.
 *
 * Règle de déplacement (demandée par la famille) : entre deux étapes
 * connues, la personne reste au premier lieu jusqu'au MILIEU de l'intervalle
 * (la médiane dans le temps), puis passe au suivant. Né à Paris en 1900,
 * mort à Marseille en 1980 : Paris jusqu'en 1940, Marseille ensuite.
 *
 * Présence : de la naissance au décès. Décès inconnu : jusqu'à aujourd'hui
 * pour une personne plausiblement vivante (moins de MAX_AGE ans, ou une
 * adresse actuelle sans date de naissance) ; sinon jusqu'à la dernière étape
 * plus une marge, et au moins jusqu'à un âge « typique ». Naissance
 * inconnue : même logique à rebours depuis la première étape.
 *
 * Anonyme comme la couche « où vit la famille » : ni nom ni identifiant,
 * coordonnées arrondies à ~1 km, dates au dixième d'année (~5 semaines,
 * pas de date de naissance exacte), pistes mélangées.
 */
class FamilyTimelineService
{
    /** Au-delà, une personne sans date de décès n'est plus supposée vivante. */
    public const MAX_AGE = 105;

    /** Marge après la dernière étape (ou avant la première) quand la borne manque. */
    public const MARGIN_YEARS = 10;

    /** Âge « typique » : présence minimale autour d'une seule borne de vie connue. */
    public const TYPICAL_AGE = 70;

    /** 0,01° ≈ 1 km, comme la heatmap des adresses. */
    private const COORD_DECIMALS = 2;

    /** Dixième d'année. */
    private const YEAR_DECIMALS = 1;

    public function __construct(private GeocodeService $geocoder) {}

    /**
     * @param  iterable<Person>  $people  avec leurs résidences chargées (lifeEvents)
     * @return array{
     *     tracks: list<list<array{from: float, to: float, lat: float, lng: float}>>,
     *     range: array{from: float, to: float}|null,
     *     pending_places: int,
     * }
     */
    public function build(iterable $people, ?CarbonInterface $now = null): array
    {
        $today = self::decimalYear($now ?? now());
        $people = collect($people);

        // Lieux en texte → coordonnées, d'après le cache SEUL : jamais
        // d'appel Nominatim pendant la requête (voir people:geocode-places).
        $places = $people
            ->flatMap(fn (Person $p) => [
                $p->birth_place,
                $p->death_place,
                ...$p->lifeEvents
                    ->filter(fn ($e) => $e->type === 'residence' && ($e->latitude === null || $e->longitude === null))
                    ->pluck('place')->all(),
            ])
            ->map(fn ($place) => GeocodeService::cleanPlace($place))
            ->filter()
            ->unique()
            ->values();
        $coords = $this->geocoder->cachedCoordinatesMany($places, $pending);

        $tracks = [];
        foreach ($people as $person) {
            if ($track = $this->track($person, $coords, $today)) {
                $tracks[] = $track;
            }
        }
        // L'ordre des pistes ne doit rien trahir (ordre de création, de nom…).
        shuffle($tracks);

        return [
            'tracks' => $tracks,
            'range' => $tracks === [] ? null : [
                'from' => min(array_map(fn (array $t) => $t[0]['from'], $tracks)),
                'to' => max(array_map(fn (array $t) => $t[count($t) - 1]['to'], $tracks)),
            ],
            'pending_places' => count($pending),
        ];
    }

    /**
     * Segments d'une personne, ou null sans aucune étape datée et localisée.
     *
     * @param  array<string, array{latitude: float, longitude: float}>  $coords
     * @return list<array{from: float, to: float, lat: float, lng: float}>|null
     */
    private function track(Person $person, array $coords, float $today): ?array
    {
        $placeAt = function (?string $place) use ($coords): ?array {
            $c = $coords[GeocodeService::cleanPlace($place)] ?? null;

            return $c ? [(float) $c['latitude'], (float) $c['longitude']] : null;
        };

        // Étapes : [année, [lat, lng], ordre à date égale].
        $stops = [];
        $stop = function (?CarbonInterface $date, ?array $latLng, int $order) use (&$stops) {
            if ($date && $latLng) {
                $stops[] = ['t' => self::decimalYear($date), 'at' => $latLng, 'order' => $order];
            }
        };

        $stop($person->birth_date, $placeAt($person->birth_place), 0);
        foreach ($person->lifeEvents as $event) {
            if ($event->type !== 'residence') {
                continue;
            }
            $latLng = $event->latitude !== null && $event->longitude !== null
                ? [(float) $event->latitude, (float) $event->longitude]
                : $placeAt($event->place);
            // Arrivée, et départ s'il est connu (même lieu jusque-là).
            $stop($event->event_date, $latLng, 1);
            $stop($event->end_date, $latLng, 1);
        }
        $stop($person->death_date, $placeAt($person->death_place), 2);

        $birth = $person->birth_date ? self::decimalYear($person->birth_date) : null;
        $death = $person->death_date ? self::decimalYear($person->death_date) : null;
        $hasAddress = $person->address_latitude !== null && $person->address_longitude !== null;
        $dead = $death !== null || filled($person->death_place);
        $alive = ! $dead && ($birth !== null ? $today - $birth < self::MAX_AGE : $hasAddress);

        // Aujourd'hui : l'adresse actuelle d'une personne vivante.
        if ($alive && $hasAddress) {
            $stops[] = ['t' => $today, 'at' => [(float) $person->address_latitude, (float) $person->address_longitude], 'order' => 3];
        }
        if ($stops === []) {
            return null;
        }
        usort($stops, fn ($a, $b) => [$a['t'], $a['order']] <=> [$b['t'], $b['order']]);
        $first = $stops[0]['t'];
        $last = $stops[count($stops) - 1]['t'];

        $start = $birth ?? max(
            min($first - self::MARGIN_YEARS, $death !== null ? $death - self::TYPICAL_AGE : INF),
            $death !== null ? $death - self::MAX_AGE : -INF,
        );
        $end = $death ?? ($alive ? $today : min(
            $today,
            max($last + self::MARGIN_YEARS, $birth !== null ? $birth + self::TYPICAL_AGE : -INF),
            $birth !== null ? $birth + self::MAX_AGE : INF,
        ));
        if ($end <= $start) {
            return null;
        }

        // Étapes hors de la vie (saisie incohérente) ramenées à ses bornes ;
        // l'ordre est conservé.
        foreach ($stops as &$s) {
            $s['t'] = min(max($s['t'], $start), $end);
        }
        unset($s);

        // Chaque étape occupe sa place de la médiane précédente à la suivante ;
        // deux étapes consécutives au même endroit ne font qu'un segment.
        $segments = [];
        $n = count($stops);
        for ($i = 0; $i < $n; $i++) {
            $from = round($i === 0 ? $start : ($stops[$i - 1]['t'] + $stops[$i]['t']) / 2, self::YEAR_DECIMALS);
            $to = round($i === $n - 1 ? $end : ($stops[$i]['t'] + $stops[$i + 1]['t']) / 2, self::YEAR_DECIMALS);
            $lat = round($stops[$i]['at'][0], self::COORD_DECIMALS);
            $lng = round($stops[$i]['at'][1], self::COORD_DECIMALS);
            if ($to <= $from) {
                continue;
            }
            $k = array_key_last($segments);
            if ($k !== null && $segments[$k]['lat'] === $lat && $segments[$k]['lng'] === $lng) {
                $segments[$k]['to'] = $to;
            } else {
                $segments[] = ['from' => $from, 'to' => $to, 'lat' => $lat, 'lng' => $lng];
            }
        }

        return $segments === [] ? null : $segments;
    }

    /** 1er juillet 1940 → 1940,5 environ. */
    public static function decimalYear(CarbonInterface $date): float
    {
        $days = $date->isLeapYear() ? 366 : 365;

        return $date->year + ($date->dayOfYear - 1 + $date->secondsSinceMidnight() / 86400) / $days;
    }
}
