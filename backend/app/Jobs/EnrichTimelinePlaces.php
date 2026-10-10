<?php

namespace App\Jobs;

use App\Services\GeocodeService;
use App\Services\PlacePhotoService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Résout en arrière-plan ce que la frise n'avait pas en cache : coordonnées
 * d'un lieu en texte et photo du lieu (Wikipédia / Commons). Les appels
 * externes sont lents et en série (politesse Nominatim) : ils n'ont rien à
 * faire dans la requête de la frise, qui repart avec le cache rempli.
 */
class EnrichTimelinePlaces implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  list<array{place: ?string, latitude: ?float, longitude: ?float}>  $places
     */
    public function __construct(public array $places) {}

    public function handle(GeocodeService $geocoder, PlacePhotoService $placePhotos): void
    {
        foreach ($this->places as $p) {
            $lat = $p['latitude'] ?? null;
            $lng = $p['longitude'] ?? null;

            if (($lat === null || $lng === null) && ! empty($p['place'])) {
                $coords = $geocoder->coordinatesFor($p['place']);
                $lat = $coords['latitude'] ?? null;
                $lng = $coords['longitude'] ?? null;
            }

            if ($lat !== null && $lng !== null) {
                $placePhotos->photoFor((float) $lat, (float) $lng, $p['place'] ?? null);
            }
        }
    }

    /**
     * Un seul job par lot de lieux pendant quelques minutes : la frise peut
     * être rechargée plusieurs fois le temps que le premier job finisse.
     */
    public static function dispatchOnce(array $places): void
    {
        if ($places === []) {
            return;
        }
        $key = 'timeline-enrich:'.md5(json_encode($places));
        if (Cache::add($key, 1, now()->addMinutes(3))) {
            static::dispatch($places);
        }
    }
}
