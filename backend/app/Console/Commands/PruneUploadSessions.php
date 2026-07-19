<?php

namespace App\Console\Commands;

use App\Models\UploadSession;
use App\Services\S3Service;
use Illuminate\Console\Command;

/**
 * Nettoyage des sessions d'upload multipart abandonnées (issue #23).
 *
 * Une UploadSession n'existe que le temps d'un upload direct S3 en cours : elle
 * est supprimée à la finalisation (`complete`) ou à l'annulation (`abort`). Une
 * session qui traîne au-delà du seuil n'a donc jamais été finalisée → on abandonne
 * l'upload multipart côté S3 (sinon les parts orphelines s'accumulent et coûtent)
 * puis on supprime la session.
 */
class PruneUploadSessions extends Command
{
    protected $signature = 'memorylane:prune-upload-sessions {--hours=48 : Âge minimum (heures) au-delà duquel une session non finalisée est considérée abandonnée}';

    protected $description = 'Abandonne côté S3 et supprime les sessions d\'upload multipart jamais finalisées';

    public function handle(S3Service $s3): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);

        $sessions = UploadSession::where('created_at', '<', $cutoff)->get();

        if ($sessions->isEmpty()) {
            $this->info('Aucune session d\'upload abandonnée à nettoyer.');

            return self::SUCCESS;
        }

        $pruned = 0;
        foreach ($sessions as $session) {
            // L'abort S3 peut échouer (upload déjà expiré/supprimé côté bucket) :
            // on le journalise mais on supprime tout de même la session pour ne
            // pas la garder indéfiniment.
            try {
                $s3->abortMultipartUpload($session->s3_key, $session->upload_id);
            } catch (\Throwable $e) {
                report($e);
            }

            $session->delete();
            $pruned++;
        }

        $this->info("{$pruned} session(s) d'upload abandonnée(s) nettoyée(s) (> {$hours}h).");

        return self::SUCCESS;
    }
}
