<?php

namespace App\Services;

use App\Console\Commands\BackupDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * État de santé de l'instance : sauvegardes, files d'attente, disque,
 * recherche, erreurs. Chaque vérification renvoie
 * ['key', 'label', 'ok' => bool, 'detail' => texte lisible].
 * Utilisé par la commande horaire (alertes mail) et le widget admin.
 */
class HealthCheck
{
    /** Délai au-delà duquel une sauvegarde nocturne est jugée manquée. */
    public const BACKUP_MAX_AGE_HOURS = 26;

    /** Nombre d'erreurs journalisées sur 24 h toléré avant alerte. */
    public const MAX_ERRORS_PER_DAY = 20;

    public function __construct(
        private ?string $backupLog = null,
        private ?string $dumpDir = null,
        private ?string $logDir = null,
    ) {
        $this->backupLog ??= storage_path('logs/backup-media.log');
        $this->dumpDir ??= storage_path(BackupDatabase::LOCAL_DIR);
        $this->logDir ??= storage_path('logs');
    }

    /** @return list<array{key: string, label: string, ok: bool, detail: string}> */
    public function run(): array
    {
        $checks = [
            $this->mediaBackup(),
            $this->backupRestoreTest(),
            $this->databaseBackup(),
            $this->failedJobs(),
            $this->horizon(),
            $this->disk(),
            $this->errors(),
        ];

        if (config('scout.driver') === 'meilisearch') {
            $checks[] = $this->search();
        }

        return $checks;
    }

    private function check(string $key, string $label, bool $ok, string $detail): array
    {
        return compact('key', 'label', 'ok', 'detail');
    }

    /** Dernière ligne du journal de sauvegarde contenant $needle (date UTC en tête). */
    private function lastLogEvent(string $needle): ?CarbonImmutable
    {
        if (! is_file($this->backupLog)) {
            return null;
        }
        $last = null;
        foreach (file($this->backupLog, FILE_IGNORE_NEW_LINES) as $line) {
            if (str_contains($line, $needle) && preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $m)) {
                $last = $m[1];
            }
        }

        return $last ? CarbonImmutable::createFromFormat('Y-m-d H:i:s', $last, 'UTC') : null;
    }

    private function mediaBackup(): array
    {
        $label = 'Sauvegarde des photos (OVH)';
        $ok = $this->lastLogEvent('copie : TERMINÉE OK');
        $failed = $this->lastLogEvent('copie : ÉCHEC');

        if ($failed && (! $ok || $failed->gt($ok))) {
            return $this->check('backup_media', $label, false, 'La dernière copie a échoué ('.$failed->diffForHumans().'). Voir storage/logs/backup-media.log.');
        }
        if (! $ok) {
            return $this->check('backup_media', $label, false, 'Aucune copie réussie enregistrée.');
        }
        $fresh = $ok->gt(now()->subHours(self::BACKUP_MAX_AGE_HOURS));

        return $this->check('backup_media', $label, $fresh, ($fresh ? 'Dernière copie réussie ' : 'Pas de copie depuis ').$ok->diffForHumans().'.');
    }

    private function backupRestoreTest(): array
    {
        $label = 'Test de restauration mensuel';
        $ok = $this->lastLogEvent('vérification : TERMINÉE OK');
        $failed = $this->lastLogEvent('vérification : ÉCHEC');

        if ($failed && (! $ok || $failed->gt($ok))) {
            return $this->check('backup_verify', $label, false, 'Le dernier test a échoué ('.$failed->diffForHumans().') : fichiers manquants ou différents.');
        }

        return $this->check('backup_verify', $label, true, $ok ? 'Dernier test réussi '.$ok->diffForHumans().'.' : 'Pas encore de test (le 1er du mois).');
    }

    private function databaseBackup(): array
    {
        $label = 'Sauvegarde de la base';
        $files = glob($this->dumpDir.'/memorylane-*.dump') ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $latest = $files[0] ?? null;

        if (! $latest) {
            return $this->check('backup_db', $label, false, 'Aucun dump trouvé.');
        }
        $at = CarbonImmutable::createFromTimestamp(filemtime($latest));
        $size = filesize($latest);
        $ok = $at->gt(now()->subHours(self::BACKUP_MAX_AGE_HOURS)) && $size > 100 * 1024;

        return $this->check('backup_db', $label, $ok, sprintf('Dernier dump %s (%s Mo).', $at->diffForHumans(), number_format($size / 1048576, 1, ',', ' ')));
    }

    private function failedJobs(): array
    {
        $recent = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->get(['payload']);
        if ($recent->isEmpty()) {
            return $this->check('failed_jobs', 'Tâches de fond', true, 'Aucun échec sur 24 h.');
        }
        $names = $recent->map(fn ($j) => class_basename(json_decode($j->payload, true)['displayName'] ?? '?'))
            ->countBy()->map(fn ($n, $name) => "{$name} ×{$n}")->implode(', ');

        return $this->check('failed_jobs', 'Tâches de fond', false, "{$recent->count()} échec(s) sur 24 h : {$names}. Détail dans Horizon.");
    }

    private function horizon(): array
    {
        try {
            $masters = app(MasterSupervisorRepository::class)->all();
        } catch (\Throwable $e) {
            return $this->check('horizon', 'Horizon (files d\'attente)', false, 'Statut illisible : '.$e->getMessage());
        }
        $running = collect($masters)->contains(fn ($m) => ($m->status ?? null) === 'running');

        return $this->check('horizon', 'Horizon (files d\'attente)', $running, $running
            ? 'En marche.'
            : ($masters ? 'En pause.' : 'Arrêté : les envois, conversions et notifications sont bloqués.'));
    }

    private function disk(): array
    {
        $free = @disk_free_space(storage_path()) ?: 0;
        $total = @disk_total_space(storage_path()) ?: 1;
        $ok = $free / $total > 0.10 && $free > 5 * 1024 ** 3;

        return $this->check('disk', 'Espace disque du serveur', $ok, sprintf('%s Go libres (%d %%).', number_format($free / 1024 ** 3, 0, ',', ' '), round(100 * $free / $total)));
    }

    private function errors(): array
    {
        $since = now()->subDay();
        $count = 0;
        $lastMessage = null;
        $files = array_filter([
            $this->logDir.'/laravel.log',
            $this->logDir.'/laravel-'.now()->format('Y-m-d').'.log',
            $this->logDir.'/laravel-'.now()->subDay()->format('Y-m-d').'.log',
        ], 'is_file');

        foreach ($files as $file) {
            // Les 5 derniers Mo suffisent pour couvrir 24 h.
            $handle = fopen($file, 'r');
            fseek($handle, max(0, filesize($file) - 5 * 1024 * 1024));
            while (($line = fgets($handle)) !== false) {
                // Seulement l'environnement courant : des tests lancés sur ce
                // serveur écrivent des lignes « testing.ERROR » volontaires.
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] '.preg_quote(app()->environment(), '/').'\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,140})/', $line, $m)
                    && CarbonImmutable::parse($m[1])->gte($since)) {
                    $count++;
                    $lastMessage = trim($m[3]);
                }
            }
            fclose($handle);
        }

        $ok = $count <= self::MAX_ERRORS_PER_DAY;

        return $this->check('errors', 'Erreurs de l\'application', $ok, $count === 0
            ? 'Aucune erreur sur 24 h.'
            : "{$count} erreur(s) sur 24 h".($ok ? '.' : ". Dernière : {$lastMessage}"));
    }

    private function search(): array
    {
        try {
            $status = Http::timeout(3)->get(rtrim(config('scout.meilisearch.host'), '/').'/health')->json('status');
        } catch (\Throwable) {
            $status = null;
        }

        return $this->check('search', 'Moteur de recherche', $status === 'available', $status === 'available' ? 'Disponible.' : 'Injoignable : la recherche ne répond plus.');
    }
}
