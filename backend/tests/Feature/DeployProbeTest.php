<?php

namespace Tests\Feature;

use App\Console\Commands\DeployProbeCommand;
use App\Jobs\DeployProbe;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * memorylane:deploy-probe (fin de scripts/deploy.sh) : chaque file doit être
 * traitée par un worker démarré après le début du déploiement.
 */
class DeployProbeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Les sondes s'exécutent sur place (pas de Redis en test).
        foreach (array_unique(DeployProbeCommand::QUEUES) as $connection) {
            config(["queue.connections.{$connection}" => ['driver' => 'sync']]);
        }
    }

    public function test_succeeds_when_every_queue_answers_from_a_fresh_worker(): void
    {
        $this->artisan('memorylane:deploy-probe', ['--since' => app('memorylane.booted_at') - 60])
            ->expectsOutputToContain('file default : traitée par un worker à jour')
            ->expectsOutputToContain('file faces : traitée par un worker à jour')
            ->expectsOutputToContain('file video : traitée par un worker à jour')
            ->assertSuccessful();
    }

    public function test_flags_workers_started_before_the_deploy(): void
    {
        $this->artisan('memorylane:deploy-probe', ['--since' => microtime(true) + 60])
            ->expectsOutputToContain('ancien code en mémoire')
            ->assertFailed();
    }

    public function test_flags_queues_nobody_processes(): void
    {
        Queue::fake();

        $this->artisan('memorylane:deploy-probe', ['--timeout' => 1])
            ->expectsOutputToContain('sonde non traitée')
            ->assertFailed();

        Queue::assertPushed(DeployProbe::class, 3);
    }
}
