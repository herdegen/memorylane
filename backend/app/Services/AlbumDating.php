<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Media;
use Illuminate\Support\Carbon;

/**
 * Date d'album héritée par ses photos et vidéos sans date.
 *
 * Une date propre (appareil photo, saisie à la main, import) n'est jamais
 * touchée. Une date héritée garde la trace de son album (taken_at_album_id) :
 * elle suit les changements de date de l'album, et disparaît si l'album perd
 * sa date ou si la photo en sort — sauf si un autre album daté la contient,
 * qui prend alors le relais (le plus ancien album daté d'abord).
 */
class AlbumDating
{
    /** Après un changement de date (ou la suppression) d'un album. */
    public function applyAlbum(Album $album): int
    {
        $ids = $album->media()->pluck('media.id')
            ->merge(Media::where('taken_at_album_id', $album->id)->pluck('id'))
            ->unique()
            ->all();

        return $this->syncMedia($ids);
    }

    /**
     * Recalcule la date héritée de ces médias (après un ajout à un album ou
     * un retrait). Renvoie le nombre de médias dont la date a changé.
     *
     * @param  iterable<string>  $mediaIds
     */
    public function syncMedia(iterable $mediaIds): int
    {
        $ids = collect($mediaIds)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        $changed = 0;
        Media::whereIn('id', $ids)
            ->where(fn ($q) => $q->whereNull('taken_at')->orWhereNotNull('taken_at_album_id'))
            ->with(['albums' => fn ($q) => $q->whereNotNull('albums.date')->orderBy('albums.created_at')])
            ->get()
            ->each(function (Media $media) use (&$changed) {
                $current = $media->taken_at_album_id
                    ? $media->albums->firstWhere('id', $media->taken_at_album_id)
                    : null;
                $source = $current ?? $media->albums->first();

                $takenAt = $source ? Carbon::parse($source->date->toDateString().' 12:00:00') : null;
                $albumId = $source?->id;

                if ($media->taken_at?->toDateTimeString() === $takenAt?->toDateTimeString()
                    && $media->taken_at_album_id === $albumId) {
                    return;
                }

                // Mise à jour directe : ne pas déclencher la règle « date
                // modifiée à la main » du modèle.
                Media::whereKey($media->id)->update(['taken_at' => $takenAt, 'taken_at_album_id' => $albumId]);
                $changed++;
            });

        return $changed;
    }
}
