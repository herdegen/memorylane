<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\DocumentReader;
use Illuminate\Console\Command;

/**
 * Lit le texte des photos jamais lues (les plus récentes d'abord), par
 * lots : la nuit, jusqu'à ce que tout le fonds soit passé.
 */
class ReadDocuments extends Command
{
    protected $signature = 'media:read-documents
        {--limit=300 : photos lues au plus}
        {--media=* : identifiants précis (relit même si déjà lus)}
        {--dry-run : affiche le résultat sans rien enregistrer}';

    protected $description = 'Repère les documents parmi les photos (lecture automatique du texte)';

    public function handle(DocumentReader $reader): int
    {
        if (! DocumentReader::available()) {
            $this->error('Tesseract est introuvable : lecture désactivée.');

            return self::FAILURE;
        }

        $query = Media::where('type', 'photo');
        $ids = $this->option('media');
        $ids ? $query->whereIn('id', $ids) : $query->whereNull('text_checked_at')->orderByDesc('created_at')->limit((int) $this->option('limit'));

        $documents = 0;
        $done = 0;
        foreach ($query->get() as $media) {
            if ($this->option('dry-run')) {
                $read = $reader->read($media);
                $this->line(sprintf('%s  %3s mots  %s', $media->id, $read['words'] ?? '?', mb_substr(str_replace("\n", ' ', $read['text'] ?? ''), 0, 70)));
                continue;
            }
            $result = $reader->classify($media);
            $done++;
            $documents += $result ? 1 : 0;
        }
        if (! $this->option('dry-run')) {
            $this->info("{$done} photo(s) lue(s), {$documents} document(s) repéré(s).");
        }

        return self::SUCCESS;
    }
}
