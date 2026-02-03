<?php

namespace App\Http\Controllers;

use App\Models\Person;
use App\Models\Media;
use App\Services\MediaService;
use Illuminate\Http\Request;
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
            'birth_date' => 'nullable|date',
            'death_date' => 'nullable|date|after_or_equal:birth_date',
            'avatar_media_id' => 'nullable|exists:media,id',
            'notes' => 'nullable|string|max:2000',
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

        $person->load(['avatar.conversions']);
        $person->loadCount('media');

        if ($person->avatar) {
            $person->avatar_url = $this->getAvatarUrl($person->avatar);
        }

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
            ]);
        }

        return Inertia::render('People/Show', [
            'person' => $person,
            'media' => $media,
        ]);
    }

    public function update(Request $request, Person $person)
    {
        if ($person->user_id !== auth()->id()) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'birth_date' => 'nullable|date',
            'death_date' => 'nullable|date|after_or_equal:birth_date',
            'avatar_media_id' => 'nullable|exists:media,id',
            'notes' => 'nullable|string|max:2000',
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
