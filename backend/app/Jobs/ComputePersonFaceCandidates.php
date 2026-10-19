<?php

namespace App\Jobs;

use App\Services\Vision\PersonFaceCandidates;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Calcul global des visages probables de chaque personne (~30 s), pour
 * l'écran admin « Identifier par personne ». Unique tant qu'il attend : une
 * rafale de validations n'en déclenche qu'un ; une validation pendant le
 * calcul en redemande un (le résultat porte la version des références).
 */
class ComputePersonFaceCandidates implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    // Calcul sans effet de bord : interrompu (redémarrage des workers au
    // déploiement), il est simplement refait au lieu d'échouer.
    public int $tries = 2;

    public function handle(PersonFaceCandidates $candidates): void
    {
        Cache::forever(PersonFaceCandidates::ALL_KEY, $candidates->computeAll());
    }
}
