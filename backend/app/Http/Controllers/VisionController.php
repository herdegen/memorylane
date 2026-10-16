<?php

namespace App\Http\Controllers;

use App\Rules\VisiblePerson;
use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\Person;
use App\Services\Vision\FaceCropService;
use App\Services\Vision\FaceMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisionController extends Controller
{
    public function __construct(private FaceMatcher $faceMatcher, private FaceCropService $faceCrops)
    {
    }

    /**
     * Proxy image même-origine pour la détection de visages côté navigateur.
     *
     * Les images s'affichent normalement via des URLs signées Scaleway (origine
     * différente de l'app) : face-api.js dessine l'image sur un canvas et lit
     * les pixels, ce qu'une image cross-origin sans CORS interdit (canvas
     * « tainted » → SecurityError). On streame donc les octets depuis le serveur,
     * même origine, uniquement pour l'ENTRÉE de la détection.
     *
     * ?conversion=medium proxie la conversion (~1024px) plutôt que l'original :
     * plus rapide, moins de RAM, et sans incidence sur les boîtes (stockées en %).
     */
    public function image(Request $request, Media $media): StreamedResponse
    {
        $this->authorizeMedia($media);

        $path = $media->file_path;
        $contentType = $media->mime_type;

        if ($requested = $request->query('conversion')) {
            $conversion = $media->conversions()
                ->where('conversion_name', $requested)
                ->first()
                ?? $media->conversions()
                    ->whereIn('conversion_name', ['medium', 'large', 'web'])
                    ->first();

            if ($conversion) {
                $path = $conversion->file_path;
                $contentType = $conversion->mime_type ?: 'image/jpeg';
            }
        }

        // Servi sur le domaine de l'app : uniquement des images, avec un type
        // imposé (jamais celui stocké avec le fichier) et sans interprétation.
        abort_unless(is_string($contentType) && in_array($contentType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true), 404);

        return Storage::disk(config('filesystems.default'))->response($path, null, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    /**
     * Stocker les visages détectés côté navigateur (face-api.js).
     *
     * Wipe + recreate : on remplace l'ensemble des visages du média (cohérent
     * avec reanalyze). L'embedding est conservé dès maintenant pour alimenter
     * la reconnaissance (Phase 2).
     */
    public function storeFaces(Request $request, Media $media): JsonResponse
    {
        $this->authorizeMediaOwner($media);

        // Photos ET vidéos : pour une vidéo, la détection tourne sur l'image
        // extraite (frame-poster) servie via la conversion medium.
        if (! in_array($media->type, ['photo', 'video'], true)) {
            return response()->json(['message' => 'Only photos and videos can be analyzed'], 422);
        }

        $validated = $request->validate([
            'faces' => 'present|array',
            'faces.*.bounding_box' => 'required|array',
            'faces.*.bounding_box.x' => 'required|numeric',
            'faces.*.bounding_box.y' => 'required|numeric',
            'faces.*.bounding_box.width' => 'required|numeric',
            'faces.*.bounding_box.height' => 'required|numeric',
            'faces.*.confidence' => 'nullable|numeric',
            'faces.*.embedding' => 'nullable|array|size:128',
            'faces.*.embedding.*' => 'numeric',
        ]);

        $faces = $validated['faces'];

        // Wipe + recreate en transaction : un échec à mi-course ne doit pas
        // perdre les visages existants.
        \Illuminate\Support\Facades\DB::transaction(function () use ($media, $faces) {
            $media->detectedFaces()->delete();
            FaceMatcher::bumpReferences(); // suppression en masse : pas d'événement modèle

            foreach ($faces as $face) {
                $media->detectedFaces()->create([
                    'bounding_box' => $face['bounding_box'],
                    'confidence' => $face['confidence'] ?? null,
                    'embedding' => $face['embedding'] ?? null,
                    'provider' => 'faceapi',
                    'status' => 'unmatched',
                ]);
            }

            $media->metadata()->updateOrCreate([], [
                'vision_status' => 'completed',
                'vision_provider' => 'faceapi',
                'vision_faces_count' => count($faces),
                'vision_processed_at' => now(),
                'vision_error' => null,
            ]);
        });

        app(\App\Services\Vision\AlbumParticipants::class)->classifyMedia($media);

        return response()->json([
            'status' => 'completed',
            'faces_count' => count($faces),
        ]);
    }

    /**
     * Analyse côté serveur (InsightFace) : ajoute les visages manquants et
     * les empreintes, sans rien supprimer ni réattribuer (FaceReconciler).
     */
    public function analyzeOnServer(Request $request, Media $media): JsonResponse
    {
        $this->authorizeFaceEditing($media);
        abort_unless(in_array($media->type, ['photo', 'video'], true), 422, 'Seules les photos et vidéos s\'analysent.');
        abort_unless(\App\Services\Vision\FaceEngine::enabled(), 409, 'Analyse serveur désactivée.');

        // « Forcer » (admin) : cadres non identifiés effacés, détection et
        // identification refaites ; les personnes identifiées sont conservées.
        $force = $request->boolean('force');
        abort_if($force && ! auth()->user()->isAdmin(), 403, 'Réservé à l\'admin.');

        \App\Jobs\AnalyzeFaces::dispatchSync($media, $force);

        return $this->facesPayload($media);
    }

    /**
     * Analyse côté serveur d'un lot de médias (page « Visages » par lots) :
     * mise en file, une tâche par média.
     */
    public function analyzeBatch(Request $request): JsonResponse
    {
        abort_unless(\App\Services\Vision\FaceEngine::enabled(), 409, 'Analyse serveur désactivée.');
        $validated = $request->validate(['ids' => 'required|array|max:1000', 'ids.*' => 'uuid']);

        $user = auth()->user();
        $medias = Media::whereIn('id', $validated['ids'])->whereIn('type', ['photo', 'video'])->get()
            ->filter(fn (Media $m) => $user->isAdmin() || $m->user_id === $user->id);
        $medias->each(fn (Media $m) => \App\Jobs\AnalyzeFaces::dispatch($m));

        return response()->json(['queued' => $medias->count()]);
    }

    /**
     * Ajoute UN visage dessiné manuellement (sans wiper les visages existants).
     * Utile quand la détection auto rate un visage (de profil, partiel…).
     */
    public function addFace(Request $request, Media $media): JsonResponse
    {
        $this->authorizeFaceEditing($media);

        if (! in_array($media->type, ['photo', 'video'], true)) {
            return response()->json(['message' => 'Only photos and videos can be analyzed'], 422);
        }

        $validated = $request->validate([
            'bounding_box' => 'required|array',
            'bounding_box.x' => 'required|numeric',
            'bounding_box.y' => 'required|numeric',
            'bounding_box.width' => 'required|numeric',
            'bounding_box.height' => 'required|numeric',
            'confidence' => 'nullable|numeric',
            'embedding' => 'nullable|array|size:128',
            'embedding.*' => 'numeric',
        ]);

        $face = $media->detectedFaces()->create([
            'bounding_box' => $validated['bounding_box'],
            'confidence' => $validated['confidence'] ?? null,
            'embedding' => $validated['embedding'] ?? null,
            'provider' => 'manual',
            'status' => 'unmatched',
        ]);

        // Moteur InsightFace : empreinte calculée par le service sur la zone.
        if (\App\Services\Vision\FaceEngine::enabled()) {
            $embedded = app(\App\Services\Vision\FaceEngine::class)->embedBox($media, $validated['bounding_box']);
            if ($embedded) {
                $face->forceFill(['embedding_v2' => $embedded['embedding'], 'age_estimate' => $embedded['age'], 'engine' => \App\Services\Vision\FaceReconciler::boxEngine($embedded['source'])])->save();
            }
        }

        // Le média est désormais « analysé » ; on met le compteur à jour.
        $media->metadata()->updateOrCreate([], [
            'vision_status' => 'completed',
            'vision_faces_count' => $media->detectedFaces()->whereIn('status', ['unmatched', 'matched'])->count(),
            'vision_processed_at' => now(),
        ]);

        $face->load('person');

        return response()->json($face, 201);
    }

    /**
     * Détacher une personne d'un visage matché (le repasse à unmatched).
     * Utilisé pour corriger une association erronée.
     */
    public function resetFace(DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeFaceEditing($detectedFace->media);
        $this->ensureFacePersonEditable($detectedFace);

        // Désassociation « collante » : le visage passe en `rejected` et n'est
        // plus re-matché automatiquement (sinon la correction serait défaite par
        // l'auto-association, issue #30). Il reste ré-identifiable manuellement.
        $this->faceMatcher->disassociate($detectedFace);

        return response()->json(['message' => 'Face reset']);
    }

    /**
     * Suggérer une personne pour un visage, par plus proche voisin sur les
     * visages déjà labellisés de l'utilisateur (distance euclidienne des
     * descripteurs face-api.js). id-based → fonctionne aussi au reload.
     */
    public function suggest(DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeMedia($detectedFace->media);

        // Corpus des visages labellisés du PROPRIÉTAIRE du média (pas du
        // viewer) : un membre du foyer obtient les mêmes suggestions que le
        // propriétaire (même comportement que l'auto-match admin).
        $candidates = $this->faceMatcher->rankedCandidates(
            $detectedFace,
            $this->faceMatcher->matchThreshold(),
            $detectedFace->media->user_id,
            auth()->user(),
        );

        // Suggestions limitées aux fiches du cercle courant, PLUS les personnes
        // déjà identifiées dans un album de cette photo (même d'un autre cercle) :
        // on peut nommer quelqu'un présent à l'événement sans proposer à
        // l'aveugle toute une autre famille.
        $user = auth()->user();
        $albumPeople = app(\App\Services\Vision\AlbumParticipants::class)->identifiedAround($detectedFace->media, $user);
        $albumIds = $albumPeople->pluck('id')->flip();
        $inContext = Person::inContext($user)
            ->whereIn('people.id', array_map(fn ($c) => $c['person']['id'], $candidates))
            ->pluck('people.id')
            ->flip();

        $suggestions = [];
        foreach ($candidates as $c) {
            $id = $c['person']['id'];
            if (isset($inContext[$id]) || isset($albumIds[$id])) {
                $c['on_album'] = ! isset($inContext[$id]);
                $suggestions[] = $c;
            }
        }
        $suggestions = array_slice($suggestions, 0, 8);
        $suggested = collect($suggestions)->pluck('person.id')->flip();

        return response()->json([
            'suggestions' => $suggestions,
            // Présents sur l'album mais sans ressemblance calculée : proposés
            // juste après les suggestions.
            'album_people' => $albumPeople->reject(fn ($p) => isset($suggested[$p['id']]))->values(),
        ]);
    }

    /**
     * Ids des photos de l'utilisateur pas encore analysées (pour le batch).
     */
    public function pending(): JsonResponse
    {
        $ids = Media::query()
            ->where('user_id', auth()->id())
            ->where('type', 'photo')
            ->when(
                \App\Services\Vision\FaceEngine::enabled(),
                // InsightFace : pas encore passé par le service.
                fn ($q) => $q->whereDoesntHave('metadata', fn ($m) => $m->where('vision_provider', 'insightface')),
                fn ($q) => $q->whereDoesntHave('metadata', fn ($m) => $m->where('vision_status', 'completed')),
            )
            ->pluck('id');

        return response()->json([
            'media_ids' => $ids,
            'count' => $ids->count(),
        ]);
    }

    /**
     * Get detected faces for a media.
     */
    public function faces(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        return $this->facesPayload($media);
    }

    private function facesPayload(Media $media): JsonResponse
    {
        // `rejected` inclus : un visage désassocié reste affiché (sans personne)
        // pour rester ré-identifiable manuellement (issue #30).
        $faces = $media->detectedFaces()
            ->with('person')
            ->whereIn('status', ['unmatched', 'matched', 'rejected'])
            ->get();
        DetectedFace::maskPeopleFor($faces, auth()->user());

        return response()->json($faces);
    }

    /**
     * Match a detected face to a person.
     */
    public function matchFace(Request $request, DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeFaceEditing($detectedFace->media);

        $this->ensureFacePersonEditable($detectedFace);

        $validated = $request->validate([
            'person_id' => ['required', 'uuid', new VisiblePerson],
            // Même personne déjà sur un autre visage de la photo : seulement
            // après confirmation (miroir, montage).
            'allow_duplicate' => ['sometimes', 'boolean'],
        ]);

        $this->faceMatcher->applyMatch($detectedFace, $validated['person_id'], (bool) ($validated['allow_duplicate'] ?? false), verified: true);
        $detectedFace->load('person');

        return response()->json($detectedFace);
    }

    /**
     * Recadrage carré d'UN visage détecté (vignette des quêtes « qui est-ce ? »).
     * Autorisé à quiconque peut voir le média, comme les autres lectures visage.
     */
    public function faceCrop(DetectedFace $detectedFace)
    {
        $this->authorizeMedia($detectedFace->media);

        $blob = $this->faceCrops->cropJpeg($detectedFace);
        abort_unless($blob, 404);

        return response($blob, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Auto-association en tâche de fond : associe le plus proche voisin
     * labellisé UNIQUEMENT si la distance est sous le seuil strict. Renvoie
     * {matched, person?}. L'utilisateur peut toujours corriger via resetFace.
     */
    public function autoMatch(DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeFaceEditing($detectedFace->media);

        // Même corpus que suggest : les visages labellisés du propriétaire.
        $best = $this->faceMatcher->autoMatch($detectedFace, $detectedFace->media->user_id, auth()->user());

        if (! $best) {
            return response()->json(['matched' => false]);
        }

        return response()->json([
            'matched' => true,
            'person' => $best['person'],
            'distance' => $best['distance'],
        ]);
    }

    /**
     * Dismiss a detected face.
     */
    public function dismissFace(DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeFaceEditing($detectedFace->media);
        $this->ensureFacePersonEditable($detectedFace);

        // Rejeter un visage retire aussi son association éventuelle (person_id +
        // pivot), pas seulement son affichage (issue #30, incohérence secondaire).
        $this->faceMatcher->disassociate($detectedFace, 'dismissed');

        return response()->json(['message' => 'Face dismissed']);
    }

    /**
     * Identification collaborative (phase 2c foyers) : tout compte pouvant
     * VOIR le média (propriétaire, album partagé, foyer) peut participer —
     * lecture des visages ET association/correction. La re-détection
     * destructive (storeFaces/reanalyze) reste réservée au propriétaire,
     * cf. authorizeMediaOwner.
     */
    private function authorizeMedia(Media $media): void
    {
        Gate::authorize('view', $media);
    }

    /**
     * Opérations destructives (wipe + re-détection) : propriétaire seul,
     * pour qu'un visiteur ne puisse pas effacer les visages ajoutés à la main.
     */
    private function authorizeMediaOwner(Media $media): void
    {
        Gate::authorize('update', $media);
    }

    /**
     * Un visage identifié comme une fiche d'un autre cercle ne peut être ni
     * ré-identifié ni désassocié par ce viewer (il ne la voit pas).
     */
    /**
     * Identifier, ajouter, ignorer ou analyser les visages d'une photo :
     * réservé à l'admin et à la personne qui a envoyé la photo (les autres
     * membres voient les visages et passent par les quêtes).
     */
    private function authorizeFaceEditing(?Media $media): void
    {
        $user = auth()->user();
        abort_unless($media && ($user->isAdmin() || $media->user_id === $user->id), 403, 'Seuls l\'admin et la personne qui a envoyé la photo identifient ses visages.');
    }

    private function ensureFacePersonEditable(DetectedFace $face): void
    {
        abort_unless($face->personVisibleTo(auth()->user()), 403, 'Ce visage est identifié dans un autre cercle.');
    }

    /**
     * « Personne de connu ici » : sur ces photos (visibles du compte), tous
     * les visages encore à identifier passent en « personne inconnue »
     * (restent visibles, ne sont plus proposés). Les visages nommés ne
     * bougent pas.
     */
    public function noKnownFaces(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'media_ids' => ['required', 'array', 'max:5000'],
            'media_ids.*' => ['uuid'],
        ]);

        $mediaIds = Media::accessibleBy($request->user())
            ->whereIn('media.id', $validated['media_ids'])
            ->pluck('media.id');

        $count = DetectedFace::whereIn('media_id', $mediaIds)
            ->where('status', 'unmatched')
            ->whereNull('person_id')
            ->update(['status' => FaceMatcher::REJECTED_STATUS]);

        return response()->json(['marked' => $count, 'photos' => $mediaIds->count()]);
    }
}
