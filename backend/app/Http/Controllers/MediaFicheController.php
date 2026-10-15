<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\Person;
use App\Rules\VisiblePerson;
use App\Services\FichePublisher;
use App\Services\Vision\FacePlausibility;
use Illuminate\Http\Request;

/**
 * « Ajouter à la fiche » : publier un média sur la fiche d'une personne
 * (visible de tous ceux qui voient la fiche ; validation par la personne si
 * elle a un compte), le retirer, et la décision de la personne.
 */
class MediaFicheController extends Controller
{
    public function __construct(private FichePublisher $fiches) {}

    /** Le propriétaire du média (ou l'admin) le met sur la fiche d'une personne. */
    public function store(Request $request, Media $media)
    {
        abort_unless($media->user_id === $request->user()->id || $request->user()->isAdmin(), 403);
        $validated = $request->validate(['person_id' => ['required', 'uuid', new VisiblePerson]]);
        $person = Person::findOrFail($validated['person_id']);
        app(FacePlausibility::class)->assertPossible($person, $media);

        $status = $this->fiches->publish($media, $person, $request->user());
        $first = strtok($person->name, ' ') ?: $person->name;

        return response()->json([
            'status' => $status,
            'message' => $status === FichePublisher::PUBLISHED
                ? "Sur la fiche de {$first}, visible par tous."
                : "{$first} doit valider : en attendant, seuls vous deux le voyez.",
        ]);
    }

    /** Retirer de la fiche publique : propriétaire du média, admin, ou la personne. */
    public function destroy(Request $request, Media $media, Person $person)
    {
        $user = $request->user();
        abort_unless($media->user_id === $user->id || $user->isAdmin() || $user->person_id === $person->id, 403);
        $this->fiches->withdraw($media, $person);

        return response()->json(['message' => 'Retiré de la fiche : seuls ceux qui y avaient déjà accès le voient.']);
    }

    /** La personne concernée valide (afficher pour tout le monde) ou garde privé. */
    public function decide(Request $request, Media $media)
    {
        $validated = $request->validate(['publish' => ['required', 'boolean']]);
        $this->fiches->decide($media, $request->user(), (bool) $validated['publish']);

        return response()->json(['message' => $validated['publish'] ? 'Affiché sur ta fiche pour tout le monde.' : 'Gardé privé.']);
    }
}
