<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Person;
use App\Services\MediaService;
use Illuminate\Http\Request;

/**
 * « Vos souvenirs ensemble » (fiche d'un ami, mode amis) : à la place de la
 * frise « Sa vie » généalogique, les photos du cercle où l'on apparaît
 * ensemble — première photo, chapitres par album/année, dernière photo.
 * Sans fiche « moi » (ou sur sa propre fiche) : les photos de la personne.
 */
class FriendshipController extends Controller
{
    private const THUMBS = 4;

    public function __construct(private MediaService $mediaService) {}

    public function show(Request $request, Person $person)
    {
        $user = $request->user();
        $selfId = $user->person_id !== $person->id ? $user->person_id : null;

        $media = Media::inContext($user)
            ->where('type', 'photo')
            ->whereHas('people', fn ($q) => $q->where('people.id', $person->id))
            ->when($selfId, fn ($q) => $q->whereHas('people', fn ($p) => $p->where('people.id', $selfId)))
            ->with(['conversions', 'albums:id,name'])
            ->orderByRaw('COALESCE(taken_at, created_at)')
            ->get();

        if ($media->isEmpty()) {
            return response()->json(['together' => (bool) $selfId, 'total' => 0, 'chapters' => []]);
        }

        $this->mediaService->hydrateSignedUrls($media);
        $thumb = fn (Media $m) => [
            'id' => $m->id,
            'url' => $this->mediaService->thumbnailUrl($m),
            'date' => ($m->taken_at ?? $m->created_at)?->toDateString(),
        ];

        // Chapitres : un par album (le plus ancien d'abord), puis les photos
        // hors album regroupées par année.
        $chapters = [];
        foreach ($media as $m) {
            $album = $m->albums->first();
            $year = ($m->taken_at ?? $m->created_at)?->year;
            $key = $album ? 'album:'.$album->id : 'year:'.$year;
            $chapters[$key] ??= [
                'title' => $album?->name ?? ($year ? __('Photos de :year', ['year' => $year]) : __('Sans date')),
                'album_id' => $album?->id,
                'year' => $year,
                'count' => 0,
                'thumbs' => [],
            ];
            $chapters[$key]['count']++;
            if (count($chapters[$key]['thumbs']) < self::THUMBS) {
                $chapters[$key]['thumbs'][] = $thumb($m);
            }
        }

        return response()->json([
            'together' => (bool) $selfId,
            'total' => $media->count(),
            'first' => $thumb($media->first()),
            'last' => $thumb($media->last()),
            'chapters' => array_values($chapters),
        ]);
    }
}
