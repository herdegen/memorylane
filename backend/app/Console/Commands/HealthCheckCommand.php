<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\SystemHealthAlert;
use App\Services\HealthCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/**
 * Vérification horaire de l'instance (sauvegardes, files, disque, erreurs…).
 * Mail aux administrateurs quand un problème apparaît (puis au plus toutes
 * les 12 h tant qu'il dure) et quand tout revient à la normale. Le résultat
 * est gardé en cache pour le widget « État du système » de l'admin.
 */
class HealthCheckCommand extends Command
{
    protected $signature = 'memorylane:health-check
                            {--pause= : Suspendre les alertes N minutes (déploiement en cours)}';

    protected $description = 'Vérifie sauvegardes, files d\'attente, disque et erreurs ; alerte les administrateurs';

    public const RESULT_KEY = 'health:last';

    public const ALERT_KEY = 'health:alerted';

    public const REALERT_HOURS = 12;

    public const PAUSE_KEY = 'health:paused_until';

    public const TRANSIENT_KEY = 'health:transient_failing';

    /**
     * Contrôles qui échouent brièvement pendant un redémarrage (déploiement) :
     * on n'alerte que s'ils échouent deux vérifications de suite.
     */
    public const TRANSIENT = ['horizon', 'search'];

    public function handle(HealthCheck $health): int
    {
        // scripts/deploy.sh : « on déploie, ne pas alerter pendant N minutes ».
        if ($minutes = (int) $this->option('pause')) {
            Cache::put(self::PAUSE_KEY, now()->addMinutes($minutes)->toIso8601String(), now()->addMinutes($minutes));
            $this->info("Alertes suspendues {$minutes} min (déploiement).");

            return self::SUCCESS;
        }

        $checks = $health->run();
        Cache::forever(self::RESULT_KEY, ['checked_at' => now()->toIso8601String(), 'checks' => $checks]);

        $this->table(['', 'Vérification', 'Détail'], array_map(
            fn ($c) => [$c['ok'] ? 'OK' : '!!', $c['label'], $c['detail']],
            $checks,
        ));

        $problems = array_values(array_filter($checks, fn ($c) => ! $c['ok']));

        // Pendant un déploiement : résultat gardé pour le widget, mais ni mail
        // ni signal d'échec à la supervision externe.
        if (Cache::has(self::PAUSE_KEY)) {
            $this->warn('Déploiement en cours : alertes suspendues.');

            return self::SUCCESS;
        }

        // Un redémarrage (Horizon, Meilisearch) ne compte que s'il dure.
        $previousTransient = Cache::get(self::TRANSIENT_KEY, []);
        $failingTransient = collect($problems)->pluck('key')->intersect(self::TRANSIENT)->values()->all();
        Cache::forever(self::TRANSIENT_KEY, $failingTransient);
        $problems = array_values(array_filter($problems, fn ($p) => ! in_array($p['key'], self::TRANSIENT, true)
            || in_array($p['key'], $previousTransient, true)));

        $this->notifyIfNeeded($problems);
        $this->ping($problems === []);

        // Toujours « réussie » : les problèmes sont signalés par mail et à la
        // supervision ; un code d'échec ferait journaliser une erreur à chaque
        // heure par le planificateur (et gonflerait le contrôle « erreurs »).
        return self::SUCCESS;
    }

    private function notifyIfNeeded(array $problems): void
    {
        $previous = Cache::get(self::ALERT_KEY);

        if ($problems === []) {
            if ($previous) {
                $this->notifyAdmins([]);
                Cache::forget(self::ALERT_KEY);
            }

            return;
        }

        $signature = collect($problems)->pluck('key')->sort()->implode(',');
        $due = ! $previous
            || $previous['signature'] !== $signature
            || now()->subHours(self::REALERT_HOURS)->gte($previous['at']);

        if ($due) {
            $this->notifyAdmins($problems);
            Cache::forever(self::ALERT_KEY, ['signature' => $signature, 'at' => now()->toIso8601String()]);
        }
    }

    private function notifyAdmins(array $problems): void
    {
        $admins = User::query()->where('role', 'admin')->whereNotNull('email')->get();
        Notification::send($admins, new SystemHealthAlert(array_map(
            fn ($p) => ['label' => $p['label'], 'detail' => $p['detail']],
            $problems,
        )));
    }

    /** Ping de supervision externe (convention healthchecks.io : /fail). */
    private function ping(bool $ok): void
    {
        $url = config('services.healthchecks.ping_url');
        if (! $url) {
            return;
        }
        try {
            Http::timeout(5)->get($ok ? $url : rtrim($url, '/').'/fail');
        } catch (\Throwable $e) {
            $this->warn('Ping de supervision impossible : '.$e->getMessage());
        }
    }
}
