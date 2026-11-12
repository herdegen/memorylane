<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Sonde de déploiement : traitée par un worker, elle note quand ce worker a
 * démarré. Un worker démarré avant le déploiement tourne avec l'ancien code
 * (cf. incident de juillet 2026 : envoi S3 cassé en silence).
 */
class DeployProbe implements ShouldQueue
{
    use Queueable;

    public $tries = 1;

    public function __construct(public string $token) {}

    public function handle(): void
    {
        Cache::put("deploy-probe:{$this->token}", app('memorylane.booted_at'), now()->addMinutes(10));
    }
}
