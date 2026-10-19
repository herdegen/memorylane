<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\DepartureService;
use Illuminate\Console\Command;

/**
 * Applique les départs de l'application arrivés au bout du délai de grâce.
 */
class ProcessDepartures extends Command
{
    protected $signature = 'memorylane:process-departures {--dry-run : Liste sans rien appliquer}';

    protected $description = 'Applique les départs de l\'application programmés il y a plus de '.DepartureService::GRACE_DAYS.' jours';

    public function handle(DepartureService $departures): int
    {
        $due = User::whereNotNull('departure_requested_at')
            ->where('departure_requested_at', '<=', now()->subDays(DepartureService::GRACE_DAYS))
            ->get();

        foreach ($due as $user) {
            if ($this->option('dry-run')) {
                $this->line("À traiter : {$user->name} ({$user->id})");

                continue;
            }
            try {
                $departures->finalizeAccountDeparture($user);
                $this->info("Départ appliqué : {$user->name}");
            } catch (\Throwable $e) {
                report($e);
                $this->error("Échec pour {$user->name} : {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
