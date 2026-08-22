<?php

namespace App\Console\Commands;

use App\Services\SimilarMediaClusterer;
use Illuminate\Console\Command;

/**
 * (Re)calcule les groupes de quasi-doublons (issue #42, tranche 3) à partir
 * des empreintes perceptuelles. À lancer après un gros import, ou depuis
 * l'écran « Quasi-doublons » du backoffice (bouton « Recalculer »).
 */
class ClusterSimilarMedia extends Command
{
    protected $signature = 'media:cluster-similar {--threshold=' . SimilarMediaClusterer::DEFAULT_THRESHOLD . ' : Distance de Hamming maximale (0-64, plus bas = plus strict)}';

    protected $description = 'Regroupe les photos quasi identiques (distance de Hamming des dHash)';

    public function handle(SimilarMediaClusterer $clusterer): int
    {
        $threshold = (int) $this->option('threshold');

        if ($threshold < 0 || $threshold > 64) {
            $this->error('Le seuil doit être compris entre 0 et 64.');

            return self::INVALID;
        }

        $result = $clusterer->cluster($threshold);

        $this->info("Photos analysées : {$result['photos']} — groupes : {$result['groups']} — photos groupées : {$result['grouped']} (seuil {$threshold})");

        return self::SUCCESS;
    }
}
