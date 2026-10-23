<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoBuilder;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Site de démonstration : (re)construit tout de zéro — base vide, famille
 * fictive, photos — puis prend l'instantané que demo:reset remet en place
 * chaque nuit. Refuse de tourner hors d'une base de démo.
 */
class DemoBuild extends Command
{
    protected $signature = 'demo:build {--force : Ne pas demander confirmation}';

    protected $description = 'Construit la démo (famille fictive) et son instantané de remise à zéro';

    public function handle(DemoBuilder $builder): int
    {
        DemoBuilder::assertDemoDatabase();

        if (! $this->option('force') && ! $this->confirm('Tout le contenu de la base de démo sera effacé et reconstruit. Continuer ?')) {
            return self::FAILURE;
        }

        $this->call('migrate:fresh', ['--force' => true]);
        $result = $builder->build(fn (string $line) => $this->line($line));

        $this->call('scout:sync-index-settings');
        foreach (['App\\Models\\Person', 'App\\Models\\Album', 'App\\Models\\Media', 'App\\Models\\Tag'] as $model) {
            $this->callSilently('scout:import', ['model' => $model]);
        }

        if (! $this->snapshot()) {
            return self::FAILURE;
        }

        $this->info("Démo prête : {$result['people']} fiches, {$result['photos']} photos.");
        $this->line("Compte d'administration : {$result['admin_email']} / {$result['admin_password']} (à garder)");

        return self::SUCCESS;
    }

    private function snapshot(): bool
    {
        $path = config('demo.snapshot');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $db = config('database.connections.pgsql');
        $dump = new Process(['pg_dump', '--format=custom', '--no-owner', '--file', $path, $db['database']], null, [
            'PGHOST' => $db['host'], 'PGPORT' => (string) $db['port'], 'PGUSER' => $db['username'], 'PGPASSWORD' => $db['password'],
        ]);
        $dump->setTimeout(600)->run();
        if (! $dump->isSuccessful()) {
            $this->error('Instantané impossible : '.$dump->getErrorOutput());

            return false;
        }
        $this->info('Instantané : '.$path);

        return true;
    }
}
