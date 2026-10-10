<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Services\MediaService;
use App\Services\NetworkService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Vue « Réseau » : graphe des personnes reliées par les photos.
 */
class NetworkController extends Controller
{
    public function __construct(
        private NetworkService $network,
        private MediaService $mediaService,
    ) {}

    public function index()
    {
        return Inertia::render('Network/Index');
    }

    public function data(Request $request)
    {
        return response()->json($this->network->graphFor($request->user()));
    }

    /**
     * Photos où deux personnes apparaissent ensemble (parmi celles que le
     * viewer peut voir) — panneau « Photos ensemble ».
     */
    public function together(Request $request)
    {
        $validated = $request->validate([
            'a' => ['required', 'uuid'],
            'b' => ['required', 'uuid', 'different:a'],
        ]);

        $media = $this->network->mediaTogether($request->user(), $validated['a'], $validated['b']);

        return response()->json($media->map(fn (Media $m) => [
            'id' => $m->id,
            'thumbnail_url' => $this->mediaService->thumbnailUrl($m),
            'taken_at' => $m->taken_at?->toDateString(),
            'type' => $m->type,
        ])->values());
    }
}
