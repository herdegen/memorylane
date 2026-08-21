<?php

namespace App\Console\Commands;

use App\Services\NameDayService;
use Illuminate\Console\Command;

/**
 * Pré-remplit le cache des fêtes des prénoms (aujourd'hui + les jours qui
 * viennent) pour que l'accueil n'ait jamais à attendre l'API Nominis.
 */
class RefreshNameDays extends Command
{
    protected $signature = 'memorylane:refresh-namedays {--days=3 : Nombre de jours à précharger}';

    protected $description = 'Rafraîchit le cache des fêtes des prénoms (API Nominis)';

    public function handle(NameDayService $service): int
    {
        $days = max(1, (int) $this->option('days'));

        for ($i = 0; $i < $days; $i++) {
            $date = now()->addDays($i);
            $names = $service->refresh($date);
            $this->line($date->toDateString() . ' : ' . (count($names) ? implode(', ', $names) : '(vide)'));
        }

        return self::SUCCESS;
    }
}
