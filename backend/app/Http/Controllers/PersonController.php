<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PersonController extends Controller
{
    protected MediaService $mediaService;

    public function __construct(MediaService $mediaService)
    {
        $this->mediaService = $mediaService;
    }

    public function index(Request $request)
    {
        $people = Person::where('user_id', auth()->id())
            ->withCount('media')
            ->with(['avatar.conversions'])
            ->orderBy('name')
            ->get();

        $people->transform(function ($person) {
            if ($person->avatar) {
                $person->avatar_url = $this->getAvatarUrl($person->avatar);
            }
            return $person;
        });

        if ($request->wantsJson()) {
            return response()->json($people);
        }

        return Inertia::render('People/Index', [
            'people' => $people,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'gender' => 'nullable|in:M,F,U',
            'maiden_name' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'birth_place' => 'nullable|string|max:255',
            'death_date' => 'nullable|date|after_or_equal:birth_date',
            'death_place' => 'nullable|string|max:255',
            'avatar_media_id' => 'nullable|exists:media,id',
            'notes' => 'nullable|string|max:2000',
            'father_id' => 'nullable|exists:people,id',
            'mother_id' => 'nullable|exists:people,id',
        ]);

        $person = Person::create([
            ...$validated,
            'user_id' => auth()->id(),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Personne creee',
                'person' => $person,
            ], 201);
        }

        return redirect()->route('people.show', $person)
            ->with('success', 'Personne creee');
    }

    public function show(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $person->load(['avatar.conversions', 'father', 'mother']);
        $person->loadCount('media');

        if ($person->avatar) {
            $person->avatar_url = $this->getAvatarUrl($person->avatar);
        }

        // Load children
        $children = Person::where('father_id', $person->id)
            ->orWhere('mother_id', $person->id)
            ->with('avatar.conversions')
            ->get();

        $children->transform(function ($child) {
            if ($child->avatar) {
                $child->avatar_url = $this->getAvatarUrl($child->avatar);
            }
            return $child;
        });

        // Load spouses (bidirectional)
        $spouseIds = DB::table('person_relationships')
            ->where(function ($q) use ($person) {
                $q->where('person1_id', $person->id)
                    ->orWhere('person2_id', $person->id);
            })
            ->get()
            ->map(fn ($r) => $r->person1_id === $person->id ? $r->person2_id : $r->person1_id);

        $spouses = Person::whereIn('id', $spouseIds)
            ->with('avatar.conversions')
            ->get();

        $spouses->transform(function ($spouse) {
            if ($spouse->avatar) {
                $spouse->avatar_url = $this->getAvatarUrl($spouse->avatar);
            }
            return $spouse;
        });

        $media = $person->media()
            ->with(['conversions', 'tags'])
            ->orderBy('taken_at', 'desc')
            ->orderBy('uploaded_at', 'desc')
            ->paginate(24);

        $media->getCollection()->transform(function ($item) {
            $item->url = $this->mediaService->getSignedUrl($item);
            if ($item->conversions) {
                $item->conversions->transform(function ($conv) {
                    $conv->url = $this->mediaService->getSignedUrl($conv, $conv->file_path);
                    return $conv;
                });
            }
            return $item;
        });

        if ($request->wantsJson()) {
            return response()->json([
                'person' => $person,
                'media' => $media,
                'children' => $children,
                'spouses' => $spouses,
            ]);
        }

        return Inertia::render('People/Show', [
            'person' => $person,
            'media' => $media,
            'father' => $person->father,
            'mother' => $person->mother,
            'children' => $children,
            'spouses' => $spouses,
        ]);
    }

    public function update(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'gender' => 'nullable|in:M,F,U',
            'maiden_name' => 'nullable|string|max:255',
            'birth_date' => 'nullable|date',
            'birth_place' => 'nullable|string|max:255',
            'death_date' => 'nullable|date|after_or_equal:birth_date',
            'death_place' => 'nullable|string|max:255',
            'avatar_media_id' => 'nullable|exists:media,id',
            'notes' => 'nullable|string|max:2000',
            'father_id' => 'nullable|exists:people,id',
            'mother_id' => 'nullable|exists:people,id',
        ]);

        $person->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Personne mise a jour',
                'person' => $person,
            ]);
        }

        return redirect()->back()->with('success', 'Personne mise a jour');
    }

    public function destroy(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $person->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Personne supprimee',
            ]);
        }

        return redirect()->route('people.index')
            ->with('success', 'Personne supprimee');
    }

    public function attachToMedia(Request $request)
    {
        $validated = $request->validate([
            'media_id' => 'required|exists:media,id',
            'person_id' => 'required|exists:people,id',
        ]);

        $media = Media::findOrFail($validated['media_id']);
        $person = Person::findOrFail($validated['person_id']);

        if ($media->user_id !== auth()->id() || $person->user_id !== auth()->id()) {
            abort(403);
        }

        if (!$media->people()->where('person_id', $person->id)->exists()) {
            $media->people()->attach($person->id);
        }

        return response()->json([
            'message' => 'Personne ajoutee au media',
        ]);
    }

    public function detachFromMedia(Request $request)
    {
        $validated = $request->validate([
            'media_id' => 'required|exists:media,id',
            'person_id' => 'required|exists:people,id',
        ]);

        $media = Media::findOrFail($validated['media_id']);
        $person = Person::findOrFail($validated['person_id']);

        if ($media->user_id !== auth()->id() || $person->user_id !== auth()->id()) {
            abort(403);
        }

        $media->people()->detach($person->id);

        return response()->json([
            'message' => 'Personne retiree du media',
        ]);
    }

    public function setParent(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'parent_id' => 'required|exists:people,id',
            'parent_type' => 'required|in:father,mother',
        ]);

        $parent = Person::findOrFail($validated['parent_id']);
        if ($parent->user_id !== auth()->id()) {
            abort(403);
        }

        if ($validated['parent_id'] === $person->id) {
            return response()->json(['message' => 'Une personne ne peut pas etre son propre parent'], 422);
        }

        $person->update([
            $validated['parent_type'].'_id' => $validated['parent_id'],
        ]);

        return response()->json([
            'message' => 'Parent defini',
            'person' => $person->fresh(),
        ]);
    }

    public function removeParent(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'parent_type' => 'required|in:father,mother',
        ]);

        $person->update([
            $validated['parent_type'].'_id' => null,
        ]);

        return response()->json(['message' => 'Parent retire']);
    }

    public function addSpouse(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'spouse_id' => 'required|exists:people,id',
            'type' => 'nullable|in:spouse,partner',
            'start_date' => 'nullable|date',
            'start_place' => 'nullable|string|max:255',
        ]);

        $spouse = Person::findOrFail($validated['spouse_id']);
        if ($spouse->user_id !== auth()->id()) {
            abort(403);
        }

        if ($validated['spouse_id'] === $person->id) {
            return response()->json(['message' => 'Une personne ne peut pas etre son propre conjoint'], 422);
        }

        $ids = [$person->id, $validated['spouse_id']];
        sort($ids);

        PersonRelationship::firstOrCreate(
            ['person1_id' => $ids[0], 'person2_id' => $ids[1], 'type' => $validated['type'] ?? 'spouse'],
            [
                'start_date' => $validated['start_date'] ?? null,
                'start_place' => $validated['start_place'] ?? null,
            ]
        );

        return response()->json(['message' => 'Relation ajoutee']);
    }

    public function removeSpouse(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'spouse_id' => 'required|exists:people,id',
        ]);

        $ids = [$person->id, $validated['spouse_id']];
        sort($ids);

        PersonRelationship::where('person1_id', $ids[0])
            ->where('person2_id', $ids[1])
            ->delete();

        return response()->json(['message' => 'Relation supprimee']);
    }

    private function getAvatarUrl(Media $media): string
    {
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
