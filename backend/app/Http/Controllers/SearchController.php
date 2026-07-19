<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Media;
use App\Models\Person;
use App\Models\Tag;
use App\Services\MediaService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        protected MediaService $mediaService
    ) {}

    /**
     * Recherche unifiée : médias, personnes, albums et tags en une requête.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $query = $validated['q'];

        $userId = auth()->id();

        // Médias privés : on contraint l'hydratation aux médias du propriétaire.
        // On élargit le take() car le filtre user_id s'applique après le scoring
        // Scout (sinon on risque de perdre des résultats pertinents).
        $media = $this->safeSearch(fn () => Media::search($query)
            ->query(fn ($builder) => $builder->with('conversions')->where('user_id', $userId))
            ->take(30)
            ->get()
            ->take(8));

        $media->each(function ($item) {
            $item->url = $this->mediaService->getSignedUrl($item);
            $thumb = $item->conversions->firstWhere('conversion_name', 'thumbnail')
                ?? $item->conversions->firstWhere('conversion_name', 'small');
            $item->thumbnail_url = $thumb
                ? $this->mediaService->getSignedUrl($item, $thumb->file_path)
                : $item->url;
        });

        return response()->json([
            'media' => $media->map(fn ($m) => [
                'id'            => $m->id,
                'original_name' => $m->original_name,
                'title'         => $m->title,
                'type'          => $m->type,
                'thumbnail_url' => $m->thumbnail_url,
            ])->values(),
            'people' => $this->safeSearch(fn () => Person::search($query)
                ->query(fn ($b) => $b->with('avatar.conversions')->withCount([
                    'detectedFaces as matched_faces_count' => fn ($q) => $q
                        ->where('status', 'matched')->whereNotNull('bounding_box'),
                ]))
                ->take(5)->get())
                ->map(fn ($p) => [
                    'id'         => $p->id,
                    'name'       => $p->name,
                    'avatar_url' => $this->personAvatarUrl($p),
                ])->values(),
            'albums' => $this->safeSearch(fn () => Album::search($query)
                ->query(fn ($builder) => $builder->where('user_id', $userId))
                ->take(30)->get()->take(5))
                ->map(fn ($a) => [
                    'id'          => $a->id,
                    'name'        => $a->name,
                    'description' => $a->description,
                ])->values(),
            'tags' => $this->safeSearch(fn () => Tag::search($query)->take(5)->get())
                ->map(fn ($t) => [
                    'id'    => $t->id,
                    'name'  => $t->name,
                    'color' => $t->color,
                ])->values(),
        ]);
    }

    /**
     * Exécute une recherche Scout en isolant les échecs (ex. index Meilisearch
     * absent quand un modèle n'a encore aucun enregistrement) : renvoie une
     * collection vide au lieu de faire échouer toute la recherche.
     */
    private function safeSearch(callable $search): \Illuminate\Support\Collection
    {
        try {
            return collect($search());
        } catch (\Throwable $e) {
            report($e);

            return collect();
        }
    }

    /**
     * URL d'avatar d'une personne : photo de profil signée sinon (fallback)
     * le recadrage du visage tagué (endpoint people.faceAvatar). Nécessite
     * `avatar.conversions` et `matched_faces_count` chargés.
     */
    private function personAvatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            $thumb = $person->avatar->conversions->firstWhere('conversion_name', 'small')
                ?? $person->avatar->conversions->first();

            return $thumb
                ? $this->mediaService->getSignedUrl($person->avatar, $thumb->file_path)
                : $this->mediaService->getSignedUrl($person->avatar);
        }

        if (($person->matched_faces_count ?? 0) > 0) {
            return url("/people/{$person->id}/face-avatar");
        }

        return null;
    }
}
