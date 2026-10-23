<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoBuilder;
use App\Services\Demo\DemoFreshener;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Remise à zéro nocturne de la démo : l'instantané de demo:build est remis en
 * place (tout ce que les visiteurs ont modifié disparaît), puis les dates
 * sont recalées sur aujourd'hui. Refuse de tourner hors d'une base de démo.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Remet la démo dans son état de départ (chaque nuit)';

    public function handle(DemoFreshener $freshener): int
    {
        DemoBuilder::assertDemoDatabase();

        $path = config('demo.snapshot');
        if (! is_file($path)) {
            $this->error("Pas d'instantané ({$path}) : lancer d'abord demo:build.");

            return self::FAILURE;
        }

        $db = config('database.connections.pgsql');
        $restore = new Process(['pg_restore', '--clean', '--if-exists', '--no-owner', '--single-transaction', '--dbname', $db['database'], $path], null, [
            'PGHOST' => $db['host'], 'PGPORT' => (string) $db['port'], 'PGUSER' => $db['username'], 'PGPASSWORD' => $db['password'],
        ]);
        $restore->setTimeout(600)->run();
        if (! $restore->isSuccessful()) {
            $this->error('Restauration impossible : '.$restore->getErrorOutput());

            return self::FAILURE;
        }

        $freshener->freshen();
        foreach (['App\\Models\\Person', 'App\\Models\\Album', 'App\\Models\\Media', 'App\\Models\\Tag'] as $model) {
            $this->callSilently('scout:flush', ['model' => $model]);
            $this->callSilently('scout:import', ['model' => $model]);
        }

        $this->info('Démo remise à zéro.');

        return self::SUCCESS;
    }
}
