<?php

namespace App\Services;

use App\Jobs\GeocodePendingPlaces;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * « La famille dans le temps » (carte) : pour chaque fiche, la suite des
 * lieux où elle a vécu, réduite à des segments { from, to, lat, lng } en
 * années décimales.
 *
 * Lieux de vie (« étapes ») : naissance, résidences datées, union (lieu du
 * mariage, pour les deux conjoints), naissance d'un enfant (les deux parents
 * vivaient là), décès et, pour une personne vivante, son adresse actuelle.
 *
 * Règle de déplacement (demandée par la famille) : entre deux étapes
 * connues, la personne reste au premier lieu jusqu'au MILIEU de l'intervalle
 * (la médiane dans le temps), puis passe au suivant. Né à Paris en 1900,
 * mort à Marseille en 1980 : Paris jusqu'en 1940, Marseille ensuite.
 *
 * Sorties temporaires (voyages) : depuis 1990, les photos géolocalisées où
 * la personne est identifiée la montrent, le temps du mois de la photo, au
 * lieu le plus photographié de ce mois — si c'est assez loin de chez elle.
 * Elles ne comptent PAS dans les médianes : la personne revient ensuite sur
 * sa trajectoire de fond. Ces segments portent `trip: true`.
 *
 * Présence : de la naissance au décès. Décès inconnu : jusqu'à aujourd'hui
 * pour une personne plausiblement vivante (moins de MAX_AGE ans, ou une
 * adresse actuelle sans date de naissance) ; sinon jusqu'à la dernière étape
 * plus une marge, et au moins jusqu'à un âge « typique ». Naissance
 * inconnue : même logique à rebours depuis la première étape.
 *
 * Anonyme comme la couche « où vit la famille » : ni nom ni identifiant,
 * coordonnées arrondies à ~1 km, dates au dixième d'année (au mois pour les
 * sorties), pistes mélangées.
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

    /** Bornes d'une sortie : au millième d'année (le mois, à quelques heures près). */
    private const TRIP_DECIMALS = 3;

    public function __construct(private GeocodeService $geocoder) {}

    /**
     * @param  iterable<Person>  $people  avec leurs résidences chargées (lifeEvents)
     *                                    et father_id / mother_id
     * @param  User|null  $viewer  sorties photo : seulement les photos qu'il peut voir (aucune sans lui)
     * @return array{
     *     tracks: list<list<array{from: float, to: float, lat: float, lng: float, trip?: bool}>>,
     *     range: array{from: float, to: float}|null,
     *     pending_places: int,
     * }
     */
    public function build(iterable $people, ?User $viewer = null, ?CarbonInterface $now = null): array
    {
        $today = self::decimalYear($now ?? now());
        $people = collect($people)->keyBy('id');
        $ids = $people->keys()->all();

        // Lieux de vie déduits : [id de la fiche => [[date, lieu], …]].
        $implied = [];
        foreach ($this->unions($ids) as $union) {
            foreach ([$union->person1_id, $union->person2_id] as $id) {
                if ($people->has($id)) {
                    $implied[$id][] = [$union->start_date, $union->start_place];
                }
            }
        }
        foreach ($people as $child) {
            if ($child->birth_date && filled($child->birth_place)) {
                foreach (array_unique(array_filter([$child->father_id, $child->mother_id])) as $id) {
                    if ($people->has($id)) {
                        $implied[$id][] = [$child->birth_date, $child->birth_place];
                    }
                }
            }
        }

        // Lieux en texte → coordonnées, d'après le cache SEUL : jamais
        // d'appel Nominatim pendant la requête (géocodage en tâche de fond).
        $places = $people
            ->flatMap(fn (Person $p) => [
                $p->birth_place,
                $p->death_place,
                ...$p->lifeEvents
                    ->filter(fn ($e) => $e->type === 'residence' && ($e->latitude === null || $e->longitude === null))
                    ->pluck('place')->all(),
                ...array_column($implied[$p->id] ?? [], 1),
            ])
            ->map(fn ($place) => GeocodeService::cleanPlace($place))
            ->filter()
            ->unique()
            ->values();
        $coords = $this->geocoder->cachedCoordinatesMany($places, $pending);
        // Lieux jamais tentés (données anciennes) : géocodés en tâche de fond,
        // ils apparaîtront aux prochains affichages.
        GeocodePendingPlaces::enqueue($pending);

        $trips = $viewer ? $this->photoMonths($ids, $viewer) : [];

        $tracks = [];
        foreach ($people as $person) {
            $track = $this->track($person, $coords, $today, $implied[$person->id] ?? [], $trips[$person->id] ?? []);
            if ($track) {
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
     * @param  list<array{0: CarbonInterface, 1: string}>  $implied  unions, naissances des enfants
     * @param  list<array{from: float, to: float, lat: float, lng: float}>  $trips  sorties photo, par mois
     * @return list<array{from: float, to: float, lat: float, lng: float, trip?: bool}>|null
     */
    private function track(Person $person, array $coords, float $today, array $implied, array $trips): ?array
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

        // Lieu de naissance inconnu mais lieu de décès connu : on suppose la
        // personne née au même endroit (choix de l'utilisateur, 2026-11-05).
        $stop($person->birth_date, $placeAt($person->birth_place) ?? ($person->birth_place ? null : $placeAt($person->death_place)), 0);
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
        // Union, naissance d'un enfant : la personne vivait là à cette date.
        foreach ($implied as [$date, $place]) {
            $stop($date, $placeAt($place), 1);
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
        if ($segments === []) {
            return null;
        }

        // Sorties temporaires, posées PAR-DESSUS la trajectoire de fond
        // (calculée sans elles) : dans la vie, et assez loin de chez soi.
        $minKm = (float) config('memorylane.timeline.trip_min_km', 30);
        foreach ($trips as $trip) {
            if ($trip['from'] < $segments[0]['from'] || $trip['to'] > $segments[count($segments) - 1]['to']) {
                continue;
            }
            $home = self::segmentAt($segments, ($trip['from'] + $trip['to']) / 2);
            if ($home === null || ! empty($home['trip']) || self::km($home, $trip) < $minKm) {
                continue;
            }
            $segments = self::overlay($segments, $trip + ['trip' => true]);
        }

        return $segments;
    }

    /**
     * Unions datées et localisées des fiches.
     *
     * @param  list<string>  $ids
     */
    private function unions(array $ids)
    {
        if ($ids === []) {
            return collect();
        }

        return PersonRelationship::query()
            ->where(fn ($q) => $q->whereIn('person1_id', $ids)->orWhereIn('person2_id', $ids))
            ->whereNotNull('start_date')
            ->whereNotNull('start_place')
            ->where('start_place', '<>', '')
            ->get(['person1_id', 'person2_id', 'start_date', 'start_place']);
    }

    /**
     * Sorties photo, agrégées en SQL : photos datées et géolocalisées où la
     * fiche est identifiée, que le compte peut voir (côté famille), depuis
     * l'année où la frise passe au mois. Par personne et par mois, le lieu
     * le plus photographié (cellules de ~10 km, puis position moyenne dans
     * la cellule). Une identification refusée sur la fiche ne compte pas.
     *
     * @param  list<string>  $ids
     * @return array<string, list<array{from: float, to: float, lat: float, lng: float}>>
     */
    private function photoMonths(array $ids, User $viewer): array
    {
        if ($ids === []) {
            return [];
        }
        $since = (int) config('memorylane.timeline.monthly_from', 1990);
        $minPhotos = max(1, (int) config('memorylane.timeline.trip_min_photos', 1));

        $rows = DB::table('media_person as mp')
            ->join('media as m', 'm.id', '=', 'mp.media_id')
            ->join('media_metadata as mm', 'mm.media_id', '=', 'm.id')
            ->whereIn('mp.person_id', $ids)
            ->where(fn ($q) => $q->whereNull('mp.fiche_status')->orWhere('mp.fiche_status', '<>', 'private'))
            ->whereNull('m.deleted_at')
            ->where('m.taken_at', '>=', "{$since}-01-01")
            ->whereNotNull('mm.latitude')
            ->whereNotNull('mm.longitude')
            ->whereIn('m.id', Media::query()->inContext($viewer)->select('media.id'))
            ->selectRaw("mp.person_id, to_char(m.taken_at, 'YYYY-MM') as month,
                round(mm.latitude::numeric, 1) as cell_lat, round(mm.longitude::numeric, 1) as cell_lng,
                count(*) as photos, avg(mm.latitude) as lat, avg(mm.longitude) as lng")
            ->groupBy('mp.person_id', 'month', 'cell_lat', 'cell_lng')
            ->get();

        // Par personne et par mois : la cellule la plus photographiée.
        $best = [];
        foreach ($rows as $row) {
            $key = $row->person_id.'|'.$row->month;
            if (! isset($best[$key]) || $row->photos > $best[$key]->photos) {
                $best[$key] = $row;
            }
        }

        $trips = [];
        foreach ($best as $row) {
            if ($row->photos < $minPhotos) {
                continue;
            }
            $month = Carbon::createFromFormat('!Y-m', $row->month, 'UTC');
            $trips[$row->person_id][] = [
                'from' => round(self::decimalYear($month), self::TRIP_DECIMALS),
                'to' => round(self::decimalYear($month->copy()->addMonth()), self::TRIP_DECIMALS),
                'lat' => round((float) $row->lat, self::COORD_DECIMALS),
                'lng' => round((float) $row->lng, self::COORD_DECIMALS),
            ];
        }

        return $trips;
    }

    /** Segment qui couvre l'instant $t. */
    private static function segmentAt(array $segments, float $t): ?array
    {
        foreach ($segments as $segment) {
            if ($t >= $segment['from'] && $t < $segment['to']) {
                return $segment;
            }
        }

        return null;
    }

    /** Insère une sortie dans la trajectoire : le segment de fond est coupé autour. */
    private static function overlay(array $segments, array $trip): array
    {
        $out = [];
        foreach ($segments as $segment) {
            if ($segment['to'] <= $trip['from'] || $segment['from'] >= $trip['to']) {
                $out[] = $segment;

                continue;
            }
            if ($segment['from'] < $trip['from']) {
                $out[] = array_merge($segment, ['to' => $trip['from']]);
            }
            if ($segment['to'] > $trip['to']) {
                $out[] = array_merge($segment, ['from' => $trip['to']]);
            }
        }
        $out[] = $trip;
        usort($out, fn ($a, $b) => $a['from'] <=> $b['from']);

        return $out;
    }

    /** Distance (km) entre deux points { lat, lng }. */
    private static function km(array $a, array $b): float
    {
        $rad = M_PI / 180;
        $h = sin(($b['lat'] - $a['lat']) * $rad / 2) ** 2
            + cos($a['lat'] * $rad) * cos($b['lat'] * $rad) * sin(($b['lng'] - $a['lng']) * $rad / 2) ** 2;

        return 12742 * asin(min(1, sqrt($h)));
    }

    /** 1er juillet 1940 → 1940,5 environ. */
    public static function decimalYear(CarbonInterface $date): float
    {
        $days = $date->isLeapYear() ? 366 : 365;

        return $date->year + ($date->dayOfYear - 1 + $date->secondsSinceMidnight() / 86400) / $days;
    }
}
