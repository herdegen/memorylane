<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\LifeEvent;
use App\Models\Media;
use App\Models\Person;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Moments de vie (frise) rattachés à une personne. Création/édition réservées
 * à un admin ou au créateur de la fiche (cf. #20).
 */
class LifeEventController extends Controller
{
    /**
     * Types de moments : liste FERMÉE (« sous-moments » définis) pour que le
     * diaporama puisse s'appuyer dessus (icônes, mise en scène par type).
     */
    private const TYPES = [
        // Moments de vie
        'moment', 'job', 'education', 'residence',
        // Fêtes religieuses
        'bapteme', 'communion', 'confirmation', 'mariage_religieux',
        // Fêtes & célébrations
        'mariage', 'fiancailles', 'anniversaire', 'diplome', 'fete',
        'custom',
    ];

    public function store(Request $request, Person $person)
    {
        $this->authorizeManage($person);

        $data = $this->validated($request);
        $data['person_id'] = $person->id;
        $data['user_id'] = auth()->id();

        $event = LifeEvent::create($data);

        return response()->json(['message' => 'Moment ajouté', 'event' => $event], 201);
    }

    public function update(Request $request, LifeEvent $lifeEvent)
    {
        $this->authorizeManage($lifeEvent->person);

        $lifeEvent->update($this->validated($request));

        return response()->json(['message' => 'Moment mis à jour', 'event' => $lifeEvent->fresh()]);
    }

    public function destroy(LifeEvent $lifeEvent)
    {
        $this->authorizeManage($lifeEvent->person);

        $lifeEvent->delete();

        return response()->json(['message' => 'Moment supprimé']);
    }

    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['nullable', Rule::in(self::TYPES)],
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'place' => 'nullable|string|max:255',
            'latitude' => 'nullable|numeric|between:-90,90|required_with:longitude',
            'longitude' => 'nullable|numeric|between:-180,180|required_with:latitude',
            'event_date' => 'required|date',
            'end_date' => 'nullable|date|after_or_equal:event_date',
            'media_id' => 'nullable|uuid|exists:media,id',
            'album_id' => 'nullable|uuid|exists:albums,id',
        ]);

        // Le média illustrant le moment doit être accessible au visiteur.
        if (! empty($validated['media_id'])) {
            $accessible = Media::accessibleBy(auth()->user())
                ->whereKey($validated['media_id'])
                ->exists();
            abort_unless($accessible, 403);
        }

        // Idem pour l'album lié (galerie du moment).
        if (! empty($validated['album_id'])) {
            $accessible = Album::accessibleBy(auth()->user())
                ->whereKey($validated['album_id'])
                ->exists();
            abort_unless($accessible, 403);
        }

        $validated['type'] = $validated['type'] ?? 'moment';

        return $validated;
    }

    /**
     * Gérable par un admin ou le créateur de la fiche.
     */
    private function authorizeManage(Person $person): void
    {
        $user = auth()->user();
        abort_unless($user !== null && ($user->isAdmin() || $person->user_id === $user->id), 403);
    }
}
