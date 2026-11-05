<?php

namespace App\Jobs;

use App\Services\GeocodeService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Géocodage AUTOMATIQUE des lieux saisis (lieu de naissance ou de décès
 * d'une fiche, lieu d'une union, lieu de vie sans coordonnées), d'où qu'ils
 * viennent : fiche, arbre, quêtes, import GEDCOM, back-office — et des
 * lieux que la carte « Dans le temps » trouve sans coordonnées.
 *
 * L'enregistrement ne fait que noter le lieu dans une liste d'attente
 * partagée (cache) et lancer CE job, unique : aucun appel réseau pendant la
 * requête de l'utilisateur. Le job vide la liste au rythme de Nominatim
 * (1 requête par seconde au plus, entre tous les processus : voir
 * GeocodeService::throttle), par lots pour ne pas monopoliser la file. Un
 * lieu déjà résolu — y compris un « introuvable » mémorisé — n'est jamais
 * remis en liste. La passe de nuit (people:geocode-places) reste le filet
 * de sécurité.
 */
class GeocodePendingPlaces implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use Queueable;

    /** Liste d'attente partagée : [lieu en minuscules => lieu]. */
    public const PENDING_KEY = 'geocode:pending';

    private const LOCK = 'geocode:pending:lock';

    /** Lieux traités par passage (~35 s) : la file « default » reste disponible. */
    public const BATCH = 30;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function handle(GeocodeService $geocoder): void
    {
        for ($i = 0; $i < self::BATCH; $i++) {
            $place = self::take();
            if ($place === null) {
                return;
            }
            if ($geocoder->isResolved($place)) {
                continue;
            }
            // Nominatim injoignable : on s'arrête là ; la liste reprendra au
            // prochain lieu saisi, et la passe de nuit rattrape le reste.
            if ($geocoder->lookup($place) === false) {
                return;
            }
        }

        if (self::pending() !== []) {
            static::dispatch()->delay(now()->addSeconds(5));
        }
    }

    /**
     * Note des lieux à géocoder et lance le job. Ne lève jamais d'erreur :
     * un souci de cache ne doit pas empêcher d'enregistrer une fiche.
     *
     * @param  iterable<?string>  $places
     */
    public static function enqueue(iterable $places): void
    {
        if (! config('memorylane.geocode_auto', true)) {
            return;
        }

        try {
            $geocoder = app(GeocodeService::class);
            $new = [];
            foreach ($places as $place) {
                $place = GeocodeService::cleanPlace($place);
                if (mb_strlen($place) >= 3 && ! $geocoder->isResolved($place)) {
                    $new[mb_strtolower($place)] = $place;
                }
            }
            if ($new === []) {
                return;
            }

            Cache::lock(self::LOCK, 10)->block(5, function () use ($new) {
                Cache::put(self::PENDING_KEY, self::pending() + $new, now()->addDays(2));
            });
            static::dispatch();
        } catch (\Throwable $e) {
            Log::warning('GeocodePendingPlaces: lieux non mis en attente', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Lieux d'un modèle qui viennent d'être saisis ou modifiés.
     *
     * @param  list<string>  $fields
     */
    public static function enqueueChanged(Model $model, array $fields): void
    {
        $places = [];
        foreach ($fields as $field) {
            if (($model->wasRecentlyCreated || $model->wasChanged($field)) && filled($model->{$field})) {
                $places[] = $model->{$field};
            }
        }
        if ($places !== []) {
            self::enqueue($places);
        }
    }

    /** @return array<string, string> */
    public static function pending(): array
    {
        $list = Cache::get(self::PENDING_KEY, []);

        return is_array($list) ? $list : [];
    }

    /** Retire et renvoie le prochain lieu de la liste. */
    private static function take(): ?string
    {
        return Cache::lock(self::LOCK, 10)->block(5, function () {
            $list = self::pending();
            if ($list === []) {
                return null;
            }
            $place = array_shift($list);
            Cache::put(self::PENDING_KEY, $list, now()->addDays(2));

            return $place;
        });
    }
}
