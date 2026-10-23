<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonLink;
use App\Rules\VisiblePerson;
use Illuminate\Http\Request;

/**
 * « Comment on s'est connus » entre une personne et une autre (par défaut la
 * fiche « moi » du visiteur) : chaque compte raconte sa version. Les deux
 * fiches doivent être visibles (liaison {person} + VisiblePerson).
 */
class PersonLinkController extends Controller
{
    public function index(Request $request, Person $person)
    {
        $other = $this->otherId($request, $person);
        if (! $other) {
            return response()->json(['with' => null, 'versions' => []]);
        }

        $viewer = $request->user();
        $versions = PersonLink::forPair($person->id, $other)
            ->with(['author:id,name', 'via' => fn ($q) => $q->visibleTo($viewer)->select('people.id', 'people.name')])
            ->orderBy('created_at')
            ->get()
            ->map(fn (PersonLink $l) => [
                'id' => $l->id,
                'mine' => $l->created_by === $viewer->id,
                'author' => $l->author?->name,
                'context' => $l->context,
                'context_label' => PersonLink::contextLabels()[$l->context] ?? null,
                'via' => $l->via ? ['id' => $l->via->id, 'name' => $l->via->name] : null,
                'story' => $l->story,
                'year' => $l->met_year,
            ]);

        return response()->json([
            'with' => $other,
            'contexts' => collect(PersonLink::contextLabels())->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'versions' => $versions->values(),
        ]);
    }

    public function upsert(Request $request, Person $person)
    {
        $validated = $request->validate([
            'with' => ['nullable', 'uuid', new VisiblePerson],
            'context' => ['required', 'in:'.implode(',', array_keys(PersonLink::CONTEXTS))],
            'via_person_id' => ['nullable', 'uuid', new VisiblePerson],
            'story' => ['nullable', 'string', 'max:500'],
            'year' => ['nullable', 'integer', 'between:1900,'.now()->year],
        ]);

        $other = $this->otherId($request, $person);
        abort_unless($other, 422, __('Reliez d\'abord votre compte à votre fiche (« C\'est moi »).'));

        [$p1, $p2] = PersonLink::pairKey($person->id, $other);

        PersonLink::updateOrCreate(
            ['person1_id' => $p1, 'person2_id' => $p2, 'created_by' => $request->user()->id],
            [
                'context' => $validated['context'],
                'via_person_id' => $validated['via_person_id'] ?? null,
                'story' => $validated['story'] ?? null,
                'met_year' => $validated['year'] ?? null,
            ],
        );

        return $this->index($request, $person);
    }

    public function destroy(Request $request, Person $person)
    {
        $other = $this->otherId($request, $person);
        abort_unless($other, 422);

        PersonLink::forPair($person->id, $other)->where('created_by', $request->user()->id)->delete();

        return $this->index($request, $person);
    }

    /** L'autre personne de la paire : `with` (validée visible) sinon « moi ». */
    private function otherId(Request $request, Person $person): ?string
    {
        $with = $request->input('with');
        if ($with) {
            $request->validate(['with' => ['uuid', new VisiblePerson]]);
        }
        $other = $with ?: $request->user()->person_id;

        return $other && $other !== $person->id ? $other : null;
    }
}
