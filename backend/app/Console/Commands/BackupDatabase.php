<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Sauvegarde quotidienne de la base PostgreSQL (format custom pg_dump,
 * compressé) : copie locale dans storage/app/private/backups/db + copie hors
 * serveur sur le bucket Scaleway (préfixe privé _backups/db/).
 *
 * Rétention (locale et distante) : 7 derniers jours, dimanches des 4
 * dernières semaines, 1ers du mois des 6 derniers mois.
 *
 * --check : restaure la dernière sauvegarde locale dans une base jetable et
 * vérifie qu'elle contient des données (test de restauration mensuel).
 *
 * Restauration manuelle : cf. docs/DATABASE.md § Backup & Restore.
 */
class BackupDatabase extends Command
{
    protected $signature = 'memorylane:backup-db
        {--check : Tester la restauration de la dernière sauvegarde au lieu d\'en créer une}
        {--no-upload : Ne pas copier la sauvegarde sur le stockage distant}';

    protected $description = 'Sauvegarde la base (pg_dump) en local + sur Scaleway, avec rotation';

    public const LOCAL_DIR = 'app/private/backups/db';

    public const REMOTE_PREFIX = '_backups/db';

    public const REMOTE_DISK = 'scaleway';

    /** Base jetable du test de restauration (supprimée avant et après). */
    public const CHECK_DATABASE = 'memorylane_restore_check';

    public function handle(): int
    {
        return $this->option('check') ? $this->checkRestore() : $this->backup();
    }

    protected function backup(): int
    {
        $dir = storage_path(self::LOCAL_DIR);
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        $name = 'memorylane-' . now()->format('Y-m-d_His') . '.dump';
        $path = "{$dir}/{$name}";

        $dump = $this->pgProcess(['pg_dump', '--format=custom', '--compress=6', '--no-owner', '--file', $path, $this->db('database')]);
        $dump->setTimeout(1800)->run();

        if (! $dump->isSuccessful() || ! is_file($path) || filesize($path) === 0) {
            @unlink($path);

            return $this->fail_("pg_dump a échoué : " . trim($dump->getErrorOutput()));
        }

        // Archive lisible ? (catalogue complet, sans restaurer)
        $list = $this->pgProcess(['pg_restore', '--list', $path]);
        $list->run();
        if (! $list->isSuccessful()) {
            return $this->fail_("Sauvegarde illisible ({$name}) : " . trim($list->getErrorOutput()));
        }

        $size = round(filesize($path) / 1048576, 1);
        $this->info("Sauvegarde locale : {$name} ({$size} Mo)");

        if (! $this->option('no-upload')) {
            $stream = fopen($path, 'r');
            $ok = Storage::disk(self::REMOTE_DISK)->writeStream(self::REMOTE_PREFIX . "/{$name}", $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (! $ok) {
                return $this->fail_("Copie distante échouée pour {$name} (la copie locale est conservée).");
            }
            $this->info('Copie distante : ' . self::REMOTE_DISK . ':' . self::REMOTE_PREFIX . "/{$name}");
        }

        $this->rotate();

        Log::info('Sauvegarde base OK', ['file' => $name, 'size_mb' => $size]);

        return self::SUCCESS;
    }

    /** Supprime les sauvegardes hors politique de rétention (local + distant). */
    protected function rotate(): void
    {
        $today = today();
        $removed = 0;

        foreach (glob(storage_path(self::LOCAL_DIR) . '/memorylane-*.dump') as $file) {
            if (! self::shouldKeep(basename($file), $today)) {
                unlink($file);
                $removed++;
            }
        }

        if (! $this->option('no-upload')) {
            $disk = Storage::disk(self::REMOTE_DISK);
            foreach ($disk->files(self::REMOTE_PREFIX) as $file) {
                if (! self::shouldKeep(basename($file), $today)) {
                    $disk->delete($file);
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            $this->line("Rotation : {$removed} ancienne(s) sauvegarde(s) supprimée(s).");
        }
    }

    /**
     * Rétention : 7 jours glissants, dimanches sur 4 semaines, 1ers du mois
     * sur 6 mois. Un fichier au nom inattendu est toujours conservé.
     */
    public static function shouldKeep(string $filename, Carbon $today): bool
    {
        if (! preg_match('/^memorylane-(\d{4}-\d{2}-\d{2})_\d{6}\.dump$/', $filename, $m)) {
            return true;
        }

        $date = Carbon::parse($m[1])->startOfDay();
        $age = (int) $date->diffInDays($today->copy()->startOfDay());

        return $age < 7
            || ($date->isSunday() && $age < 28)
            || ($date->day === 1 && $age < 186);
    }

    /** Restaure la dernière sauvegarde locale dans une base jetable et compte les lignes clés. */
    protected function checkRestore(): int
    {
        $files = glob(storage_path(self::LOCAL_DIR) . '/memorylane-*.dump');
        if ($files === []) {
            return $this->fail_('Aucune sauvegarde locale à tester.');
        }
        sort($files);
        $latest = end($files);

        $db = self::CHECK_DATABASE;
        $this->pgProcess(['dropdb', '--if-exists', $db])->run();
        $create = $this->pgProcess(['createdb', $db]);
        $create->run();
        if (! $create->isSuccessful()) {
            return $this->fail_('Impossible de créer la base de test : ' . trim($create->getErrorOutput()));
        }

        try {
            $restore = $this->pgProcess(['pg_restore', '--no-owner', '--exit-on-error', '--dbname', $db, $latest]);
            $restore->setTimeout(1800)->run();
            if (! $restore->isSuccessful()) {
                return $this->fail_('Restauration échouée (' . basename($latest) . ') : ' . trim($restore->getErrorOutput()));
            }

            $count = $this->pgProcess(['psql', '-At', '-d', $db, '-c',
                'select (select count(*) from people) || \' personnes, \' || (select count(*) from media) || \' médias\'']);
            $count->run();
            $summary = trim($count->getOutput());

            if (! $count->isSuccessful() || str_starts_with($summary, '0 ')) {
                return $this->fail_('Restauration vide ou incomplète (' . basename($latest) . ") : {$summary}");
            }

            $this->info('Restauration OK de ' . basename($latest) . " : {$summary}");
            Log::info('Test de restauration OK', ['file' => basename($latest), 'summary' => $summary]);

            return self::SUCCESS;
        } finally {
            $this->pgProcess(['dropdb', '--if-exists', $db])->run();
        }
    }

    protected function pgProcess(array $command): Process
    {
        return new Process($command, null, [
            'PGHOST' => $this->db('host'),
            'PGPORT' => (string) $this->db('port'),
            'PGUSER' => $this->db('username'),
            'PGPASSWORD' => $this->db('password'),
            'PGDATABASE' => $this->db('database'),
        ]);
    }

    protected function db(string $key): mixed
    {
        return config('database.connections.pgsql.' . $key);
    }

    protected function fail_(string $message): int
    {
        $this->error($message);
        Log::error("Sauvegarde base : {$message}");

        return self::FAILURE;
    }
}
