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
        // Arbre public : lecture ouverte à tous les comptes connectés.
        $people = Person::with(['avatar.conversions'])
            ->withMatchedFacesCount()
            ->get();

        [$spouseMap, $childrenMap] = $this->buildRelationMaps();

        $nodes = $people->map(fn (Person $person) => $this->buildNode($person, $spouseMap, $childrenMap));

        return response()->json($nodes->values());
    }

    /**
     * Return a subtree centered on a specific person.
     */
    public function subtree(Request $request, Person $person)
    {
        // Arbre public : lecture ouverte à tous les comptes connectés.
        $relatedIds = $this->gatherRelatedIds($person, 3, 3);

        $people = Person::whereIn('id', $relatedIds)
            ->with(['avatar.conversions'])
            ->withMatchedFacesCount()
            ->get();

        [$spouseMap, $childrenMap] = $this->buildRelationMaps();

        $nodes = $people->map(fn (Person $p) => $this->buildNode($p, $spouseMap, $childrenMap));

        return response()->json($nodes->values());
    }

    /**
     * Charge en 2 requêtes les maps conjoint(s) et enfants de TOUTES les
     * personnes (avant : 2 requêtes PAR personne lors de la construction de
     * l'arbre complet).
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, string>>}
     */
    private function buildRelationMaps(): array
    {
        $spouseMap = [];
        foreach (DB::table('person_relationships')->get(['person1_id', 'person2_id']) as $rel) {
            $spouseMap[$rel->person1_id][] = $rel->person2_id;
            $spouseMap[$rel->person2_id][] = $rel->person1_id;
        }

        $childrenMap = [];
        $children = Person::query()
            ->where(fn ($q) => $q->whereNotNull('father_id')->orWhereNotNull('mother_id'))
            ->get(['id', 'father_id', 'mother_id']);
        foreach ($children as $child) {
            if ($child->father_id) {
                $childrenMap[$child->father_id][] = $child->id;
            }
            if ($child->mother_id) {
                $childrenMap[$child->mother_id][] = $child->id;
            }
        }

        return [$spouseMap, $childrenMap];
    }

    private function buildNode(Person $person, array $spouseMap, array $childrenMap): array
    {
        return [
            'id' => $person->id,
            'data' => [
                'name' => $person->name,
                'last_name' => $person->last_name,
                'maiden_name' => $person->maiden_name,
                'gender' => $person->gender,
                'birth_date' => $person->birth_date?->format('Y-m-d'),
                'death_date' => $person->death_date?->format('Y-m-d'),
                'birth_place' => $person->birth_place,
                'avatar_url' => $this->avatarUrl($person),
                'slug' => $person->slug,
            ],
            'rels' => [
                'father' => $person->father_id,
                'mother' => $person->mother_id,
                'spouses' => array_values(array_unique($spouseMap[$person->id] ?? [])),
                'children' => array_values(array_unique($childrenMap[$person->id] ?? [])),
            ],
        ];
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

    /**
     * URL d'avatar pour l'arbre : photo de profil sinon (fallback) recadrage
     * du visage tagué (endpoint people.faceAvatar). Nécessite matched_faces_count.
     */
    private function avatarUrl(Person $person): ?string
    {
        if ($person->avatar) {
            return $this->mediaService->thumbnailUrl($person->avatar);
        }

        if (($person->matched_faces_count ?? 0) > 0) {
            return url("/people/{$person->id}/face-avatar");
        }

        return null;
    }
}
