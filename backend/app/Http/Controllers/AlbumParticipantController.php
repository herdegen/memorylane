<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Person;
use App\Rules\VisiblePerson;
use App\Services\Vision\AlbumParticipants;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * « Qui était du voyage ? » : participants d'un album. Les enregistrer trie
 * aussitôt les visages de l'album (cf. AlbumParticipants).
 */
class AlbumParticipantController extends Controller
{
    public function __construct(private AlbumParticipants $participants) {}

    public function index(Request $request, Album $album)
    {
        Gate::authorize('view', $album);

        return response()->json([
            'participants' => $this->list($request, $album),
            'can_edit' => Gate::allows('update', $album),
            'auto_rejected' => \App\Models\DetectedFace::where('auto_rejected_album_id', $album->id)->count(),
        ]);
    }

    public function update(Request $request, Album $album)
    {
        Gate::authorize('update', $album);

        $validated = $request->validate([
            'person_ids' => ['present', 'array', 'max:200'],
            'person_ids.*' => ['uuid', new VisiblePerson],
        ]);

        $album->participants()->sync(collect($validated['person_ids'])
            ->mapWithKeys(fn ($id) => [$id => ['added_by' => $request->user()->id]])
            ->all());

        $stats = $this->participants->classifyAlbum($album);

        return response()->json([
            'participants' => $this->list($request, $album),
            'can_edit' => true,
            'auto_rejected' => $stats['rejected'],
            'to_identify' => $stats['to_identify'],
            'missing_refs' => $stats['missing_refs'],
        ]);
    }

    private function list(Request $request, Album $album): array
    {
        return $album->participants()
            ->visibleTo($request->user())
            ->orderBy('name')
            ->get(['people.id', 'people.name'])
            ->map(fn (Person $p) => ['id' => $p->id, 'name' => $p->name])
            ->all();
    }
}
