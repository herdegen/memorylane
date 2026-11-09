<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Person;
use App\Rules\VisiblePerson;
use App\Services\AlbumHighlights;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Rôles dans un album à thème : les mariés, les témoins, le baptisé, le
 * parrain et la marraine, les personnes fêtées. N'ajoute PAS de participant
 * (« Qui était là ? » trie les visages inconnus ; un rôle ne trie rien).
 */
class AlbumRoleController extends Controller
{
    public function update(Request $request, Album $album)
    {
        Gate::authorize('update', $album);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(AlbumHighlights::ROLES)],
            'person_ids' => ['present', 'array', 'max:20'],
            'person_ids.*' => ['uuid', 'distinct', new VisiblePerson],
        ]);
        $role = $validated['role'];
        $wanted = collect($validated['person_ids']);

        DB::transaction(function () use ($album, $role, $wanted, $request) {
            // Ceux que le visiteur ne voit pas (autre cercle) restent : il ne
            // gère que les siens.
            DB::table('album_roles')->where('album_id', $album->id)->where('role', $role)
                ->whereIn('person_id', Person::visibleTo($request->user())->select('people.id'))
                ->whereNotIn('person_id', $wanted)
                ->delete();
            foreach ($wanted as $personId) {
                DB::table('album_roles')->insertOrIgnore([
                    'album_id' => $album->id, 'person_id' => $personId, 'role' => $role,
                    'added_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back();
    }
}
