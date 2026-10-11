<?php

namespace App\Filament\Widgets;

use App\Console\Commands\HealthCheckCommand;
use App\Services\HealthCheck;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

/**
 * État du système en tête du tableau de bord admin : résultat de la
 * dernière vérification horaire (memorylane:health-check), relançable à la
 * demande. Lecture seule : les alertes mail restent gérées par la commande.
 */
class SystemHealth extends Widget
{
    protected string $view = 'filament.widgets.system-health';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public function getResult(): ?array
    {
        return Cache::get(HealthCheckCommand::RESULT_KEY);
    }

    public function getCheckedAgo(): ?string
    {
        $at = $this->getResult()['checked_at'] ?? null;

        return $at ? CarbonImmutable::parse($at)->diffForHumans() : null;
    }

    public function runNow(HealthCheck $health): void
    {
        Cache::forever(HealthCheckCommand::RESULT_KEY, ['checked_at' => now()->toIso8601String(), 'checks' => $health->run()]);
    }
}
