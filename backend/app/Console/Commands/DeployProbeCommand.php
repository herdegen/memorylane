<?php

namespace App\Console\Commands;

use App\Jobs\DeployProbe;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Après un déploiement : envoie une sonde sur chaque file de tâches de fond et
 * vérifie qu'elle est traitée par un worker démarré APRÈS le début du
 * déploiement (donc avec le nouveau code). Lancé par scripts/deploy.sh.
 */
class DeployProbeCommand extends Command
{
    protected $signature = 'memorylane:deploy-probe
        {--since= : horodatage Unix du début du déploiement (workers plus anciens = ancien code)}
        {--timeout=90 : secondes d\'attente maximum}';

    protected $description = 'Vérifie que chaque file de tâches de fond tourne avec le nouveau code';

    /** File => connexion (cf. config/horizon.php). */
    public const QUEUES = [
        'default' => 'redis',
        'faces' => 'redis',
        'video' => 'redis-long',
    ];

    public function handle(): int
    {
        $since = (float) ($this->option('since') ?: 0);
        $deadline = microtime(true) + (int) $this->option('timeout');

        $tokens = [];
        foreach (self::QUEUES as $queue => $connection) {
            $tokens[$queue] = (string) Str::uuid();
            DeployProbe::dispatch($tokens[$queue])->onConnection($connection)->onQueue($queue);
        }

        $results = [];
        while ($tokens && microtime(true) < $deadline) {
            foreach ($tokens as $queue => $token) {
                $bootedAt = Cache::pull("deploy-probe:{$token}");
                if ($bootedAt !== null) {
                    $results[$queue] = (float) $bootedAt;
                    unset($tokens[$queue]);
                }
            }
            if ($tokens) {
                usleep(500_000);
            }
        }

        $ok = true;
        foreach (array_keys(self::QUEUES) as $queue) {
            if (! isset($results[$queue])) {
                $this->error("✗ file {$queue} : sonde non traitée (workers arrêtés ou bloqués ?)");
                $ok = false;
            } elseif ($results[$queue] < $since) {
                $this->error("✗ file {$queue} : worker démarré avant le déploiement, ancien code en mémoire (redémarrer horizon)");
                $ok = false;
            } else {
                $this->info("✓ file {$queue} : traitée par un worker à jour");
            }
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
