<?php

namespace App\Jobs;

use App\Services\GeocodeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Noms des lieux de la carte de la télévision (« Saint-Malo ») : géocodage
 * inverse en arrière-plan, en série (politesse Nominatim, 1 requête/s). La
 * carte repart ensuite du cache.
 */
class ResolvePlaceNames implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** @param  list<array{0: float, 1: float}>  $points */
    public function __construct(public array $points) {}

    public function handle(GeocodeService $geocoder): void
    {
        foreach ($this->points as [$lat, $lng]) {
            $geocoder->placeNameFor((float) $lat, (float) $lng);
        }
    }

    /** Un seul job pour un même lot pendant quelques minutes. */
    public static function dispatchOnce(array $points): void
    {
        if ($points === []) {
            return;
        }
        if (Cache::add('place-names:'.md5(json_encode($points)), 1, now()->addMinutes(10))) {
            static::dispatch($points);
        }
    }
}
