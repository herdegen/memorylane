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
        $media = Media::search($query)
            ->query(fn ($builder) => $builder->with('conversions')->where('user_id', $userId))
            ->take(30)
            ->get()
            ->take(8);

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
            'people' => Person::search($query)->take(5)->get()
                ->map(fn ($p) => [
                    'id'         => $p->id,
                    'name'       => $p->name,
                    'avatar_url' => $p->avatar_url ?? null,
                ])->values(),
            'albums' => Album::search($query)
                ->query(fn ($builder) => $builder->where('user_id', $userId))
                ->take(30)->get()->take(5)
                ->map(fn ($a) => [
                    'id'          => $a->id,
                    'name'        => $a->name,
                    'description' => $a->description,
                ])->values(),
            'tags' => Tag::search($query)->take(5)->get()
                ->map(fn ($t) => [
                    'id'    => $t->id,
                    'name'  => $t->name,
                    'color' => $t->color,
                ])->values(),
        ]);
    }
}
