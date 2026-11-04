<?php

namespace App\Services\Newsletter;

use App\Models\Media;
use App\Models\Person;
use Carbon\Carbon;

/**
 * Outils communs aux lettres du dimanche (familles et amis) : vignettes
 * présignées, prénoms, dates relatives, historique des envois.
 *
 * La classe qui l'utilise fournit $this->media (MediaService),
 * $this->history (envois de son édition, du plus récent au plus ancien) et
 * la constante IMAGE_TTL_MINUTES.
 */
trait NewsletterHelpers
{
    /** @return list<string> réfs d'une rubrique dans les $n derniers envois */
    private function recentRefs(string $key, int $n): array
    {
        return $this->history->take($n)
            ->flatMap(fn ($send) => collect($send->sections)->where('key', $key)->pluck('ref'))
            ->filter()->values()->all();
    }

    /**
     * Encart « Il y a X visages non identifiés dans l'album Y, où tu
     * apparais » à partir d'un album trouvé par AlbumFacesFinder.
     */
    private function facesAlbumSection(?array $found): ?array
    {
        if (! $found) {
            return null;
        }

        return [
            'key' => 'faces_album',
            'ref' => $found['album']->id,
            'album' => $found['album']->name,
            'count' => $found['count'],
            'thumbs' => $this->thumbs(Media::whereIn('media.id', $found['media_ids'])->where('media.type', 'photo'), 3),
            'url' => url('/quests/faces').'?album='.$found['album']->id,
        ];
    }

    private function thumbs($query, int $n): array
    {
        return $query->with('conversions')->limit($n)->get()
            ->map(fn (Media $m) => $this->media->thumbnailUrl($m, ['small', 'thumbnail'], self::IMAGE_TTL_MINUTES, presigned: true))
            ->values()->all();
    }

    private function shortName(Person $person): string
    {
        return strtok(trim($person->first_name ?: $person->name), ' ') ?: $person->name;
    }

    private function joinNames(array $names): string
    {
        $names = array_values(array_filter($names));

        return count($names) < 2
            ? implode('', $names)
            : __(':names et :last', ['names' => implode(', ', array_slice($names, 0, -1)), 'last' => end($names)]);
    }

    private function dayLabel(Carbon $day, int $days): string
    {
        return match ($days) {
            0 => __('aujourd\'hui'),
            1 => __('demain'),
            default => $day->locale(app()->getLocale())->translatedFormat('l j'),
        };
    }
}
