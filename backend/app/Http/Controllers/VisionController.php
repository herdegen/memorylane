<?php

namespace App\Http\Controllers;

use App\Rules\VisiblePerson;
use App\Models\DetectedFace;
use App\Models\Media;
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

        return response()->json([
            'status' => 'completed',
            'faces_count' => count($faces),
        ]);
    }

    /**
     * Ajoute UN visage dessiné manuellement (sans wiper les visages existants).
     * Utile quand la détection auto rate un visage (de profil, partiel…).
     */
    public function addFace(Request $request, Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

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
        $this->authorizeMedia($detectedFace->media);
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
            FaceMatcher::MATCH_THRESHOLD,
            $detectedFace->media->user_id,
            auth()->user(),
        );

        return response()->json([
            'suggestions' => array_slice($candidates, 0, 8),
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
            ->whereDoesntHave('metadata', fn ($q) => $q->where('vision_status', 'completed'))
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
        $this->authorizeMedia($detectedFace->media);

        $this->ensureFacePersonEditable($detectedFace);

        $validated = $request->validate([
            'person_id' => ['required', 'uuid', new VisiblePerson],
        ]);

        $this->faceMatcher->applyMatch($detectedFace, $validated['person_id']);
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
        $this->authorizeMedia($detectedFace->media);

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
        $this->authorizeMedia($detectedFace->media);
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
    private function ensureFacePersonEditable(DetectedFace $face): void
    {
        abort_unless($face->personVisibleTo(auth()->user()), 403, 'Ce visage est identifié dans un autre cercle.');
    }
}
