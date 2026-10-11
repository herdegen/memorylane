<?php

namespace App\Console\Commands;

use App\Jobs\AnalyzeFaces;
use App\Models\Media;
use Illuminate\Console\Command;

/**
 * (Ré)analyse des visages par InsightFace. Sans risque : rien n'est supprimé
 * ni réattribué (cf. FaceReconciler), les empreintes face-api restent en
 * place. Par défaut, seulement les médias dont un visage n'a pas encore
 * d'empreinte InsightFace (ou jamais analysés).
 */
class ReanalyzeFaces extends Command
{
    protected $signature = 'faces:reanalyze
        {--all : tous les médias, même déjà analysés par InsightFace}
        {--media=* : identifiants de médias précis}
        {--sync : traiter ici plutôt que dans la file « faces »}';

    protected $description = 'Analyse (ou réanalyse) les visages avec InsightFace, sans rien perdre';

    public function handle(): int
    {
        $query = Media::query()->whereIn('type', ['photo', 'video'])->orderBy('created_at');

        if ($ids = $this->option('media')) {
            $query->whereIn('id', $ids);
        } elseif (! $this->option('all')) {
            $query->where(fn ($q) => $q
                ->whereHas('detectedFaces', fn ($f) => $f->whereNull('embedding_v2')->where('status', '!=', 'dismissed'))
                ->orWhereDoesntHave('metadata', fn ($m) => $m->where('vision_provider', 'insightface')));
        }

        $total = $query->count();
        $this->info("{$total} média(s) à analyser.");
        if ($total === 0) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($total);
        $query->chunkById(100, function ($medias) use ($bar) {
            foreach ($medias as $media) {
                $this->option('sync') ? AnalyzeFaces::dispatchSync($media) : AnalyzeFaces::dispatch($media);
                $bar->advance();
            }
        });
        $bar->finish();
        $this->newLine();
        $this->info($this->option('sync') ? 'Analyse terminée.' : 'Analyses envoyées dans la file « faces » (suivi : /horizon).');

        return self::SUCCESS;
    }
}
