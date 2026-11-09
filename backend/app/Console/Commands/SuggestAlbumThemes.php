<?php

namespace App\Console\Commands;

use App\Models\Album;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Propose un thème aux albums qui n'en ont pas, quand c'est évident : moment
 * de vie lié à l'album, ou mot du nom (« Mariage… », « Cousinade… »,
 * « Noël… »). Sans --apply, n'écrit rien et affiche la liste.
 */
class SuggestAlbumThemes extends Command
{
    protected $signature = 'albums:suggest-themes {--apply : enregistrer les thèmes proposés}';

    protected $description = 'Propose (et, avec --apply, enregistre) un thème pour les albums qui n\'en ont pas';

    /** Type de moment de vie → thème d'album. */
    public const FROM_EVENT = [
        'mariage' => 'mariage', 'mariage_religieux' => 'mariage', 'fiancailles' => 'mariage',
        'bapteme' => 'bapteme', 'communion' => 'bapteme', 'confirmation' => 'bapteme',
        'anniversaire' => 'anniversaire', 'voyage' => 'voyage', 'fete' => 'fete',
    ];

    /**
     * Mots du nom (minuscules, sans accents) → thème. L'ordre compte :
     * « Anniversaire 40 ans de mariage » est un anniversaire.
     */
    public const FROM_NAME = [
        'anniversaire' => ['anniversaire', 'birthday', 'cumpleanos'],
        'archives' => ['archives', 'ancetres', 'genealogie'],
        'bapteme' => ['bapteme', 'communion', 'confirmation', 'bar mitzvah', 'bat mitzvah'],
        'mariage' => ['mariage', 'noces', 'wedding', 'boda'],
        'reunion' => ['cousinade', 'reunion de famille', 'retrouvailles'],
        'fete' => ['noel', 'nouvel an', 'reveillon', 'halloween', 'paques', 'carnaval', 'soiree', 'fete'],
        'voyage' => ['voyage', 'vacances', 'sejour', 'week-end', 'weekend', 'road trip', 'ski'],
    ];

    public function handle(): int
    {
        $albums = Album::whereNull('theme')->orderBy('name')->get(['id', 'name']);
        $events = DB::table('life_events')->whereIn('album_id', $albums->pluck('id'))
            ->pluck('type', 'album_id');

        $rows = [];
        foreach ($albums as $album) {
            [$theme, $why] = self::suggest($album->name, $events[$album->id] ?? null);
            if ($theme === null) {
                continue;
            }
            $rows[] = [$album->name, $theme, $why];
            if ($this->option('apply')) {
                $album->forceFill(['theme' => $theme])->saveQuietly();
            }
        }

        $this->table(['Album', 'Thème', 'Pourquoi'], $rows);
        $this->info(sprintf('%d album(s) %s, %d sans proposition.',
            count($rows), $this->option('apply') ? 'mis à jour' : 'à mettre à jour (relancer avec --apply)',
            $albums->count() - count($rows)));

        return self::SUCCESS;
    }

    /** @return array{0: ?string, 1: ?string} [thème, raison] */
    public static function suggest(string $name, ?string $eventType = null): array
    {
        if ($eventType && isset(self::FROM_EVENT[$eventType])) {
            return [self::FROM_EVENT[$eventType], "moment de vie « {$eventType} »"];
        }
        $plain = ' '.Str::lower(Str::ascii($name)).' ';
        foreach (self::FROM_NAME as $theme => $words) {
            foreach ($words as $word) {
                if (preg_match('/(?<![a-z])'.preg_quote($word, '/').'s?(?![a-z])/', $plain)) {
                    return [$theme, "nom : « {$word} »"];
                }
            }
        }

        return [null, null];
    }
}
