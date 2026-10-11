<?php

namespace App\Console\Commands;

use App\Models\DetectedFace;
use App\Services\Vision\FaceMatcher;
use Illuminate\Console\Command;

/**
 * Association automatique (seuils stricts + garde-fous) des visages encore
 * « à identifier ». --dry-run : compte et montre sans rien écrire.
 */
class AutoMatchFaces extends Command
{
    protected $signature = 'faces:auto-match {--dry-run : simuler sans rien écrire}';

    protected $description = 'Associe automatiquement les visages à identifier quand c\'est sûr (seuils stricts)';

    public function handle(FaceMatcher $matcher): int
    {
        if (! config('memorylane.faces.auto_match')) {
            $this->warn('Association automatique désactivée (FACES_AUTO_MATCH).');

            return self::FAILURE;
        }

        $faces = DetectedFace::query()->where('status', 'unmatched')->whereNull('person_id')
            ->whereHas('media')->with('media:id,user_id,taken_at')->get();
        $matched = 0;
        $byPerson = [];

        foreach ($faces as $face) {
            if ($this->option('dry-run')) {
                $best = $this->wouldMatch($matcher, $face);
            } else {
                $best = $matcher->autoMatch($face, $face->media->user_id);
            }
            if ($best) {
                $matched++;
                $byPerson[$best['person']['name']] = ($byPerson[$best['person']['name']] ?? 0) + 1;
            }
        }

        arsort($byPerson);
        $this->info(($this->option('dry-run') ? 'Simulation : ' : '')."{$matched} visage(s) associé(s) sur {$faces->count()} à identifier.");
        foreach (array_slice($byPerson, 0, 15, true) as $name => $n) {
            $this->line("  {$name} : {$n}");
        }

        return self::SUCCESS;
    }

    /** Même décision qu'autoMatch, sans écrire (copie de ses critères). */
    private function wouldMatch(FaceMatcher $matcher, DetectedFace $face): ?array
    {
        $alreadyOnMedia = DetectedFace::where('media_id', $face->media_id)->whereNotNull('person_id')->pluck('person_id')->all();
        $candidates = array_values(array_filter(
            $matcher->rankedCandidates($face, $matcher->matchThreshold(), $face->media->user_id),
            fn ($c) => ! in_array($c['person']['id'], $alreadyOnMedia, true),
        ));
        $best = $candidates[0] ?? null;
        if (! $best || $best['distance'] > $matcher->autoThreshold() || $best['doubts'] !== []) {
            return null;
        }
        if (isset($candidates[1]) && $candidates[1]['distance'] - $best['distance'] < $matcher->threshold('margin')) {
            return null;
        }

        return $best;
    }
}
