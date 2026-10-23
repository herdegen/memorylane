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
    public const TYPES = [
        // Moments de vie
        'moment', 'job', 'education', 'residence', 'voyage',
        // Parcours & distinctions
        'military', 'distinction',
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
        $photos = $data['photos'] ?? null;
        unset($data['photos']);
        $data['person_id'] = $person->id;
        $data['user_id'] = auth()->id();

        $event = LifeEvent::create($data);
        $this->syncPhotos($event, $photos);

        return response()->json(['message' => __('Moment ajouté'), 'event' => $event], 201);
    }

    public function update(Request $request, LifeEvent $lifeEvent)
    {
        // Le moment d'une fiche qu'on ne voit pas (cercle d'amis) n'existe pas.
        abort_unless($lifeEvent->person?->isVisibleTo($request->user()), 404);
        $this->authorizeManage($lifeEvent->person);

        $data = $this->validated($request);
        $photos = $data['photos'] ?? null;
        unset($data['photos']);

        // Ce que l'éditeur ne voit pas (photo d'un autre cercle, privée) n'est
        // ni retiré ni remplacé : il ne pouvait pas le choisir.
        $hidden = $lifeEvent->photos()->whereNotIn('media.id', Media::accessibleBy($request->user())->select('id'))->pluck('media.id')->all();
        if ($photos !== null && $hidden !== []) {
            $photos = array_values(array_unique([...$photos, ...$hidden]));
        }
        if ($lifeEvent->media_id && array_key_exists('media_id', $data)
            && ! Media::accessibleBy($request->user())->whereKey($lifeEvent->media_id)->exists()) {
            unset($data['media_id']);
        }

        $lifeEvent->update($data);
        $this->syncPhotos($lifeEvent, $photos);

        return response()->json(['message' => __('Moment mis à jour'), 'event' => $lifeEvent->fresh()]);
    }

    public function destroy(LifeEvent $lifeEvent)
    {
        abort_unless($lifeEvent->person?->isVisibleTo(auth()->user()), 404);
        // Ajouter/modifier un moment : tout membre. Le supprimer (sans
        // historique) : son auteur, le créateur de la fiche ou un admin.
        $user = auth()->user();
        abort_unless(
            $user !== null && ($user->isAdmin() || $lifeEvent->user_id === $user->id || $lifeEvent->person?->user_id === $user->id),
            403
        );

        $lifeEvent->delete();

        return response()->json(['message' => __('Moment supprimé')]);
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
            // Ce qu'on affiche de la date : jour exact, mois seul ou année seule.
            'date_precision' => ['nullable', Rule::in(['day', 'month', 'year'])],
            'media_id' => 'nullable|uuid|exists:media,id',
            'album_id' => 'nullable|uuid|exists:albums,id',
            // Galerie du moment : plusieurs photos, dans l'ordre envoyé.
            'photos' => 'nullable|array|max:24',
            'photos.*' => 'uuid|distinct',
        ]);

        // Toutes les photos de la galerie doivent être accessibles au visiteur.
        if (! empty($validated['photos'])) {
            $accessible = Media::accessibleBy(auth()->user())
                ->whereIn('id', $validated['photos'])
                ->count();
            abort_unless($accessible === count($validated['photos']), 403);
        }

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
        $validated['date_precision'] = $validated['date_precision'] ?? 'day';

        return $validated;
    }

    /**
     * Remplace la galerie du moment (null = champ absent : on ne touche à rien).
     *
     * @param  list<string>|null  $photos  ids de médias, dans l'ordre d'affichage
     */
    private function syncPhotos(LifeEvent $event, ?array $photos): void
    {
        if ($photos === null) {
            return;
        }
        $pivot = [];
        foreach (array_values($photos) as $i => $id) {
            $pivot[$id] = ['order' => $i];
        }
        $event->photos()->sync($pivot);
    }

    /**
     * Gérable par un admin ou le créateur de la fiche.
     */
    private function authorizeManage(Person $person): void
    {
        abort_unless((bool) auth()->user()?->canEditFamilyTree(), 403);
    }
}
