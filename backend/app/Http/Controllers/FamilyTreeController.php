<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FamilyTreeController extends Controller
{
    public function __construct(private MediaService $mediaService) {}

    public function index()
    {
        return Inertia::render('FamilyTree/Index');
    }

    /**
     * Return the tree data as JSON for the visualization library.
     */
    public function treeData(Request $request)
    {
        $people = Person::where('user_id', auth()->id())
            ->with(['avatar.conversions'])
            ->get();

        $nodes = $people->map(fn (Person $person) => $this->buildNode($person));

        return response()->json($nodes->values());
    }

    /**
     * Return a subtree centered on a specific person.
     */
    public function subtree(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $relatedIds = $this->gatherRelatedIds($person, 3, 3);

        $people = Person::whereIn('id', $relatedIds)
            ->where('user_id', auth()->id())
            ->with(['avatar.conversions'])
            ->get();

        $nodes = $people->map(fn (Person $p) => $this->buildNode($p));

        return response()->json($nodes->values());
    }

    private function buildNode(Person $person): array
    {
        return [
            'id' => $person->id,
            'data' => [
                'name' => $person->name,
                'gender' => $person->gender,
                'birth_date' => $person->birth_date?->format('Y-m-d'),
                'death_date' => $person->death_date?->format('Y-m-d'),
                'birth_place' => $person->birth_place,
                'avatar_url' => $person->avatar ? $this->getAvatarUrl($person) : null,
                'slug' => $person->slug,
            ],
            'rels' => [
                'father' => $person->father_id,
                'mother' => $person->mother_id,
                'spouses' => $this->getSpouseIds($person),
                'children' => $this->getChildrenIds($person),
            ],
        ];
    }

    private function getSpouseIds(Person $person): array
    {
        return DB::table('person_relationships')
            ->where(function ($q) use ($person) {
                $q->where('person1_id', $person->id)
                    ->orWhere('person2_id', $person->id);
            })
            ->get()
            ->map(fn ($r) => $r->person1_id === $person->id ? $r->person2_id : $r->person1_id)
            ->values()
            ->toArray();
    }

    private function getChildrenIds(Person $person): array
    {
        return Person::where('father_id', $person->id)
            ->orWhere('mother_id', $person->id)
            ->pluck('id')
            ->toArray();
    }

    private function gatherRelatedIds(Person $person, int $ancestorDepth, int $descendantDepth): array
    {
        $ids = [$person->id];

        $this->gatherAncestors($person, $ancestorDepth, $ids);
        $this->gatherDescendants($person, $descendantDepth, $ids);

        // Spouses of all gathered people
        $spouseIds = DB::table('person_relationships')
            ->where(function ($q) use ($ids) {
                $q->whereIn('person1_id', $ids)
                    ->orWhereIn('person2_id', $ids);
            })
            ->get()
            ->flatMap(fn ($r) => [$r->person1_id, $r->person2_id])
            ->toArray();

        return array_unique(array_merge($ids, $spouseIds));
    }

    private function gatherAncestors(Person $person, int $depth, array &$ids): void
    {
        if ($depth <= 0) {
            return;
        }

        if ($person->father_id && ! in_array($person->father_id, $ids)) {
            $ids[] = $person->father_id;
            $father = Person::find($person->father_id);
            if ($father) {
                $this->gatherAncestors($father, $depth - 1, $ids);
            }
        }

        if ($person->mother_id && ! in_array($person->mother_id, $ids)) {
            $ids[] = $person->mother_id;
            $mother = Person::find($person->mother_id);
            if ($mother) {
                $this->gatherAncestors($mother, $depth - 1, $ids);
            }
        }
    }

    private function gatherDescendants(Person $person, int $depth, array &$ids): void
    {
        if ($depth <= 0) {
            return;
        }

        $children = Person::where('father_id', $person->id)
            ->orWhere('mother_id', $person->id)
            ->get();

        foreach ($children as $child) {
            if (! in_array($child->id, $ids)) {
                $ids[] = $child->id;
                $this->gatherDescendants($child, $depth - 1, $ids);
            }
        }
    }

    private function getAvatarUrl(Person $person): ?string
    {
        $media = $person->avatar;
        if (! $media) {
            return null;
        }

        if ($media->conversions && $media->conversions->count() > 0) {
            $thumb = $media->conversions->firstWhere('conversion_name', 'small')
                ?? $media->conversions->first();
            if ($thumb) {
                return $this->mediaService->getSignedUrl($media, $thumb->file_path);
            }
        }

        return $this->mediaService->getSignedUrl($media);
    }
}
