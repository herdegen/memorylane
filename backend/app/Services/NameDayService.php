<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fêtes des prénoms du calendrier français, via l'API Nominis (Église
 * catholique de France) : prénoms majeurs + dérivés du jour, mis en cache
 * localement (le calendrier ne varie pas d'une année à l'autre, mois+jour
 * suffisent comme clé). Rafraîchi par le cron `memorylane:refresh-namedays`
 * et, en secours, à la demande.
 */
class NameDayService
{
    private const ENDPOINT = 'https://nominis.cef.fr/json/nominis.php';
    private const CACHE_TTL_DAYS = 40;

    /**
     * Prénoms fêtés à cette date (majeurs + dérivés), en minuscules non
     * accentuées pour un matching direct. Vide si la source est injoignable.
     *
     * @return array<int, string>
     */
    public function namesFor(Carbon $date): array
    {
        return Cache::remember(
            $this->cacheKey($date),
            now()->addDays(self::CACHE_TTL_DAYS),
            fn () => $this->fetch($date),
        );
    }

    /**
     * Force le rafraîchissement du cache pour une date (cron).
     */
    public function refresh(Carbon $date): array
    {
        $names = $this->fetch($date);
        Cache::put($this->cacheKey($date), $names, now()->addDays(self::CACHE_TTL_DAYS));

        return $names;
    }

    /**
     * Normalisation partagée (minuscules, sans accents) pour comparer un
     * prénom de personne aux prénoms du calendrier.
     */
    public static function normalize(string $name): string
    {
        $lower = mb_strtolower(trim($name));
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if ($ascii === false) {
            $ascii = $lower;
        }

        // La translittération peut produire des apostrophes (« é » → « 'e »
        // selon la locale) : on ne garde que lettres/chiffres/tiret pour que
        // « Régine » et « Regine » se rejoignent.
        return preg_replace('/[^a-z0-9-]/', '', $ascii) ?? $ascii;
    }

    /**
     * @return array<int, string>
     */
    private function fetch(Carbon $date): array
    {
        try {
            $response = Http::timeout(6)->get(self::ENDPOINT, [
                'jour' => $date->day,
                'mois' => $date->month,
            ]);

            if (! $response->ok()) {
                return [];
            }

            $prenoms = $response->json('response.prenoms') ?? [];
            $names = array_merge(
                array_keys($prenoms['majeurs'] ?? []),
                array_keys($prenoms['derives'] ?? []),
            );

            return array_values(array_unique(array_map(
                fn ($name) => self::normalize($name),
                $names,
            )));
        } catch (\Throwable $e) {
            Log::warning('NameDayService: échec de récupération Nominis', [
                'date' => $date->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function cacheKey(Carbon $date): string
    {
        // Le calendrier est le même chaque année : clé mois-jour.
        return sprintf('namedays:%02d-%02d', $date->month, $date->day);
    }
}
