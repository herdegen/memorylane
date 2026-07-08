<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Media;
use Illuminate\Support\Facades\Log;

/**
 * Albums intelligents : le contenu est calculé à partir de règles
 * (personne, tag, année, type de média — combinées en ET) et matérialisé
 * dans le pivot album_media. Tout le reste de l'app (page album, diaporama,
 * partage) fonctionne ainsi sans changement.
 */
class SmartAlbumService
{
    /**
     * Recalcule le contenu d'un album intelligent.
     */
    public function refresh(Album $album): void
    {
        if (! $album->is_smart || empty($album->smart_rules)) {
            return;
        }

        $mediaIds = $this->buildQuery($album->smart_rules, $album->user_id)
            ->orderBy('taken_at')
            ->orderBy('uploaded_at')
            ->pluck('id');

        // sync avec ordre chronologique — remplace tout le contenu
        $album->media()->sync(
            $mediaIds->values()->mapWithKeys(fn ($id, $index) => [$id => ['order' => $index + 1]])->all()
        );

        // Couverture automatique : le premier média si aucune définie
        if (! $album->cover_media_id && $mediaIds->isNotEmpty()) {
            $album->update(['cover_media_id' => $mediaIds->first()]);
        }

        Log::info('SmartAlbumService: Refreshed', [
            'album_id' => $album->id,
            'media_count' => $mediaIds->count(),
        ]);
    }

    /**
     * Recalcule tous les albums intelligents (planifié quotidiennement).
     */
    public function refreshAll(): int
    {
        $count = 0;

        Album::where('is_smart', true)->each(function (Album $album) use (&$count) {
            $this->refresh($album);
            $count++;
        });

        return $count;
    }

    /**
     * Construit la requête média correspondant aux règles (combinées en ET).
     */
    protected function buildQuery(array $rules, ?string $userId = null)
    {
        // Un album intelligent ne pioche que dans les médias de son propriétaire.
        $query = Media::query()->where('user_id', $userId);

        if (! empty($rules['person_id'])) {
            $query->whereHas('people', fn ($q) => $q->where('people.id', $rules['person_id']));
        }

        if (! empty($rules['tag_id'])) {
            $query->whereHas('tags', fn ($q) => $q->where('tags.id', $rules['tag_id']));
        }

        if (! empty($rules['year'])) {
            $query->whereRaw('EXTRACT(YEAR FROM taken_at) = ?', [(int) $rules['year']]);
        }

        if (! empty($rules['type'])) {
            $query->where('type', $rules['type']);
        }

        return $query;
    }
}
