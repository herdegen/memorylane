<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\BranchShare;
use App\Models\Media;
use App\Models\Person;
use App\Services\BranchAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Partage « vivant » avec une branche de l'arbre (descendants d'une personne,
 * et leurs conjoints au choix) : médias de l'appelant (sélection) ou album
 * dont il est propriétaire. Pas de notification : les membres le trouvent
 * dans leurs photos.
 */
class MediaBranchShareController extends Controller
{
    /** Aperçu : combien de personnes et de comptes la branche compte. */
    public function preview(Request $request, BranchAccess $access)
    {
        $validated = $request->validate([
            'person_id' => 'required|uuid',
            'include_spouses' => 'sometimes|boolean',
        ]);
        $root = Person::visibleTo($request->user())->findOrFail($validated['person_id']);

        return response()->json($access->members($root, (bool) ($validated['include_spouses'] ?? true)));
    }

    /** Partager une sélection de médias (seulement les siens) avec une branche. */
    public function shareMedia(Request $request)
    {
        $validated = $this->validated($request, true);
        $root = Person::visibleTo($request->user())->findOrFail($validated['person_id']);
        $ids = Media::whereIn('id', $validated['media_ids'])->where('user_id', $request->user()->id)->pluck('id');

        foreach ($ids as $id) {
            BranchShare::updateOrCreate(
                ['shareable_type' => BranchShare::MEDIA, 'shareable_id' => $id, 'person_id' => $root->id],
                ['include_spouses' => (bool) ($validated['include_spouses'] ?? true), 'created_by' => $request->user()->id],
            );
        }

        return response()->json([
            'message' => trans_choice(':count média partagé avec la branche de :name|:count médias partagés avec la branche de :name', $ids->count(), ['name' => $root->name]),
            'updated' => $ids->count(),
            'skipped' => count($validated['media_ids']) - $ids->count(),
        ]);
    }

    /** Retirer le partage de branche d'une sélection de médias. */
    public function unshareMedia(Request $request)
    {
        $validated = $this->validated($request, true);
        $ids = Media::whereIn('id', $validated['media_ids'])->where('user_id', $request->user()->id)->pluck('id');
        $removed = BranchShare::where('shareable_type', BranchShare::MEDIA)->whereIn('shareable_id', $ids)
            ->where('person_id', $validated['person_id'])->delete();

        return response()->json([
            'message' => trans_choice('Partage retiré de :count média|Partage retiré de :count médias', $removed),
            'updated' => $removed,
        ]);
    }

    public function albumIndex(Request $request, Album $album)
    {
        Gate::authorize('view', $album);

        return response()->json(['shares' => BranchAccess::listFor(BranchShare::ALBUM, $album->id)]);
    }

    public function shareAlbum(Request $request, Album $album)
    {
        Gate::authorize('update', $album);
        $validated = $this->validated($request, false);
        $root = Person::visibleTo($request->user())->findOrFail($validated['person_id']);
        BranchShare::updateOrCreate(
            ['shareable_type' => BranchShare::ALBUM, 'shareable_id' => $album->id, 'person_id' => $root->id],
            ['include_spouses' => (bool) ($validated['include_spouses'] ?? true), 'created_by' => $request->user()->id],
        );

        return response()->json(['shares' => BranchAccess::listFor(BranchShare::ALBUM, $album->id)], 201);
    }

    /** Retirer un partage de branche (média ou album) : réservé au propriétaire. */
    public function destroy(Request $request, BranchShare $share)
    {
        $owner = $share->shareable_type === BranchShare::ALBUM
            ? Album::whereKey($share->shareable_id)->value('user_id')
            : Media::whereKey($share->shareable_id)->value('user_id');
        abort_unless($owner === $request->user()->id || $request->user()->isAdmin(), 403);
        $share->delete();

        return response()->json(['message' => __('Partage retiré.')]);
    }

    private function validated(Request $request, bool $media): array
    {
        return $request->validate(array_filter([
            'media_ids' => $media ? 'required|array|min:1|max:500' : null,
            'media_ids.*' => $media ? 'uuid' : null,
            'person_id' => 'required|uuid',
            'include_spouses' => 'sometimes|boolean',
        ]));
    }
}
