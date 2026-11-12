<?php

namespace App\Console\Commands;

use App\Services\FamilyClosure;
use Illuminate\Console\Command;

/** Ferme les familles dont la demande de fermeture a plus de 30 jours. */
class ProcessFamilyClosures extends Command
{
    protected $signature = 'households:process-closures';

    protected $description = 'Ferme les familles arrivées au terme de leur délai de fermeture';

    public function handle(FamilyClosure $closure): int
    {
        foreach ($closure->due()->get() as $family) {
            $closure->close($family);
            $this->line("Famille fermée : {$family->name}");
        }

        return self::SUCCESS;
    }
}
