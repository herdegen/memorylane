<?php

namespace App\Console\Commands;

use App\Models\LifeEvent;
use App\Models\Person;
use App\Services\GeocodeService;
use Illuminate\Console\Command;

/**
 * Résout en tâche de fond les lieux en texte des fiches familiales (lieux de
 * naissance, de décès, lieux de vie sans coordonnées) pour « la famille dans
 * le temps » sur la carte, qui ne lit que le cache du géocodage.
 *
 * Politesse Nominatim : une requête par seconde au plus (GeocodeService),
 * User-Agent de l'appli, cache réutilisé. Un lieu introuvable reste noté
 * « introuvable » une semaine : il n'est pas retenté à chaque passage
 * (sauf --retry-misses). Plusieurs pannes de suite : on s'arrête, la nuit
 * suivante reprendra.
 */
class GeocodePeoplePlaces extends Command
{
    protected $signature = 'people:geocode-places
        {--dry-run : Liste les lieux à résoudre sans appeler Nominatim}
        {--retry-misses : Retente aussi les lieux déjà notés introuvables}
        {--limit=0 : Nombre maximum de lieux à résoudre (0 : tous)}';

    protected $description = 'Géocode les lieux de naissance, de décès et de vie des fiches familiales (carte « dans le temps »)';

    /** Pannes consécutives de Nominatim avant d'abandonner le passage. */
    private const MAX_FAILURES = 3;

    public function handle(GeocodeService $geocoder): int
    {
        $places = $this->places();
        $retry = (bool) $this->option('retry-misses');

        // Déjà localisés / introuvables mémorisés / jamais tentés.
        $located = $geocoder->cachedCoordinatesMany($places, $pending);
        $missed = array_values(array_filter($places, fn ($p) => ! isset($located[$p]) && ! in_array($p, $pending, true)));
        $todo = $retry ? [...$pending, ...$missed] : $pending;
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $todo = array_slice($todo, 0, $limit);
        }

        $this->line(sprintf(
            '%d lieu(x) distinct(s) : %d déjà localisé(s), %d introuvable(s) en cache, %d à résoudre.',
            count($places), count($located), count($missed), count($todo),
        ));

        if ($this->option('dry-run')) {
            foreach ($todo as $place) {
                $this->line("  · {$place}");
            }

            return self::SUCCESS;
        }

        $found = 0;
        $notFound = 0;
        $failures = 0;
        foreach ($todo as $place) {
            $result = $geocoder->lookup($place, $retry);
            if ($result === false) {
                $this->warn("  ! {$place} : Nominatim injoignable");
                if (++$failures >= self::MAX_FAILURES) {
                    $this->error('Nominatim ne répond plus : arrêt, le prochain passage reprendra.');

                    return self::FAILURE;
                }

                continue;
            }
            $failures = 0;
            if ($result) {
                $found++;
                $this->line("  ✓ {$place}", verbosity: 'v');
            } else {
                $notFound++;
                $this->line("  ✗ {$place} : introuvable");
            }
        }

        $this->info("{$found} lieu(x) localisé(s), {$notFound} introuvable(s).");

        return self::SUCCESS;
    }

    /**
     * Lieux en texte des fiches du répertoire familial, nettoyés et sans
     * doublon (insensible à la casse).
     *
     * @return list<string>
     */
    private function places(): array
    {
        $raw = [];
        Person::query()
            ->where('in_family_directory', true)
            ->where(fn ($q) => $q->whereNotNull('birth_place')->orWhereNotNull('death_place'))
            ->select(['id', 'birth_place', 'death_place'])
            ->chunkById(500, function ($people) use (&$raw) {
                foreach ($people as $person) {
                    $raw[] = $person->birth_place;
                    $raw[] = $person->death_place;
                }
            });

        LifeEvent::query()
            ->where('type', 'residence')
            ->whereNotNull('place')
            ->where(fn ($q) => $q->whereNull('latitude')->orWhereNull('longitude'))
            ->whereHas('person', fn ($q) => $q->where('in_family_directory', true))
            ->pluck('place')
            ->each(function ($place) use (&$raw) {
                $raw[] = $place;
            });

        $places = [];
        foreach ($raw as $place) {
            $place = GeocodeService::cleanPlace($place);
            if (mb_strlen($place) >= 3) {
                $places[mb_strtolower($place)] ??= $place;
            }
        }

        return array_values($places);
    }
}
