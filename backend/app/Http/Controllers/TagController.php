<?php

namespace App\Http\Controllers;

use App\Models\Tag;
use App\Models\Media;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TagController extends Controller
{
    /**
     * Display a listing of all tags.
     */
    public function index(Request $request)
    {
        $query = Tag::withCount('media');

        // Search by name if provided
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        $tags = $query->orderBy('name')->get();

        if ($request->wantsJson()) {
            return response()->json($tags);
        }

        return Inertia::render('Tags/Index', [
            'tags' => $tags,
        ]);
    }

    /**
     * Store a newly created tag.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:tags,name',
            'color' => 'nullable|string|max:7',
        ]);

        $tag = Tag::create($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Tag created successfully',
                'tag' => $tag,
            ], 201);
        }

        return redirect()->back()->with('success', 'Tag créé avec succès');
    }

    /**
     * Update the specified tag.
     */
    public function update(Request $request, Tag $tag)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:tags,name,' . $tag->id,
            'color' => 'nullable|string|max:7',
        ]);

        $tag->update($validated);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Tag updated successfully',
                'tag' => $tag,
            ]);
        }

        return redirect()->back()->with('success', 'Tag modifié avec succès');
    }

    /**
     * Remove the specified tag.
     */
    public function destroy(Request $request, Tag $tag)
    {
        $tag->delete();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Tag deleted successfully',
            ]);
        }

        return redirect()->back()->with('success', 'Tag supprimé avec succès');
    }

    /**
     * Attach a tag to a media.
     */
    public function attach(Request $request)
    {
        $validated = $request->validate([
            'media_id' => 'required|exists:media,id',
            'tag_id' => 'required|exists:tags,id',
        ]);

        $media = Media::findOrFail($validated['media_id']);

        // Attach tag if not already attached
        if (!$media->tags()->where('tag_id', $validated['tag_id'])->exists()) {
            $media->tags()->attach($validated['tag_id']);
        }

        return response()->json([
            'message' => 'Tag attached successfully',
        ]);
    }

    /**
     * Detach a tag from a media.
     */
    public function detach(Request $request)
    {
        $validated = $request->validate([
            'media_id' => 'required|exists:media,id',
            'tag_id' => 'required|exists:tags,id',
        ]);

        $media = Media::findOrFail($validated['media_id']);
        $media->tags()->detach($validated['tag_id']);

        return response()->json([
            'message' => 'Tag detached successfully',
        ]);
    }

    /**
     * Get tags for a specific media.
     */
    public function mediaTags(Media $media)
    {
        $tags = $media->tags;

        return response()->json($tags);
    }
}
