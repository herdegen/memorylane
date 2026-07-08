<?php

namespace App\Http\Controllers;

use App\Jobs\AnalyzeMediaWithVision;
use App\Models\DetectedFace;
use App\Models\Media;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisionController extends Controller
{
    /**
     * Distance euclidienne maximale entre deux descripteurs pour considérer
     * qu'il s'agit de la même personne (métrique native de face-api.js).
     */
    private const MATCH_THRESHOLD = 0.6;

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

        if ($requested = $request->query('conversion')) {
            $conversion = $media->conversions()
                ->where('conversion_name', $requested)
                ->first()
                ?? $media->conversions()
                    ->whereIn('conversion_name', ['medium', 'large', 'web'])
                    ->first();

            if ($conversion) {
                $path = $conversion->file_path;
            }
        }

        return Storage::disk(config('filesystems.default'))->response($path);
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
        $this->authorizeMedia($media);

        if ($media->type !== 'photo') {
            return response()->json(['message' => 'Only photos can be analyzed'], 422);
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

        if ($media->type !== 'photo') {
            return response()->json(['message' => 'Only photos can be analyzed'], 422);
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

        if ($detectedFace->person_id) {
            $detectedFace->media->people()->detach($detectedFace->person_id);
        }

        $detectedFace->update([
            'person_id' => null,
            'status' => 'unmatched',
        ]);

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

        $target = $detectedFace->embedding;

        if (! is_array($target) || count($target) !== 128) {
            return response()->json(['suggestions' => []]);
        }

        // Visages labellisés de l'utilisateur (autres que celui-ci), avec embedding.
        $labelled = DetectedFace::query()
            ->whereNotNull('person_id')
            ->whereNotNull('embedding')
            ->where('id', '!=', $detectedFace->id)
            ->whereHas('media', fn ($q) => $q->where('user_id', auth()->id()))
            ->with('person:id,name')
            ->get();

        // Plus proche voisin par personne.
        $bestByPerson = [];

        foreach ($labelled as $face) {
            if (! $face->person) {
                continue;
            }

            $distance = $this->euclideanDistance($target, $face->embedding);

            if ($distance > self::MATCH_THRESHOLD) {
                continue;
            }

            $personId = $face->person->id;

            if (! isset($bestByPerson[$personId]) || $distance < $bestByPerson[$personId]['distance']) {
                $bestByPerson[$personId] = [
                    'person' => [
                        'id' => $face->person->id,
                        'name' => $face->person->name,
                    ],
                    'distance' => round($distance, 4),
                    // Score de similarité (0..1) : 1 = identique, 0 = au seuil.
                    'score' => round(max(0, 1 - $distance / self::MATCH_THRESHOLD), 4),
                ];
            }
        }

        $suggestions = array_values($bestByPerson);
        usort($suggestions, fn ($a, $b) => $a['distance'] <=> $b['distance']);

        return response()->json([
            'suggestions' => array_slice($suggestions, 0, 3),
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

    private function euclideanDistance(array $a, array $b): float
    {
        $sum = 0.0;
        foreach ($a as $i => $v) {
            $d = $v - ($b[$i] ?? 0);
            $sum += $d * $d;
        }

        return sqrt($sum);
    }
    /**
     * Get detected faces for a media.
     */
    public function faces(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        $faces = $media->detectedFaces()
            ->with('person')
            ->whereIn('status', ['unmatched', 'matched'])
            ->get();

        return response()->json($faces);
    }

    /**
     * Match a detected face to a person.
     */
    public function matchFace(Request $request, DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeMedia($detectedFace->media);

        $validated = $request->validate([
            'person_id' => 'required|uuid|exists:people,id',
        ]);

        // Update the detected face
        $detectedFace->update([
            'person_id' => $validated['person_id'],
            'status' => 'matched',
        ]);

        // Also create/update the media_person pivot with face_coordinates
        $detectedFace->media->people()->syncWithoutDetaching([
            $validated['person_id'] => [
                'face_coordinates' => json_encode($detectedFace->bounding_box),
            ],
        ]);

        $detectedFace->load('person');

        return response()->json($detectedFace);
    }

    /**
     * Dismiss a detected face.
     */
    public function dismissFace(DetectedFace $detectedFace): JsonResponse
    {
        $this->authorizeMedia($detectedFace->media);

        $detectedFace->update(['status' => 'dismissed']);

        return response()->json(['message' => 'Face dismissed']);
    }

    /**
     * Get vision labels for a media.
     */
    public function labels(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        $metadata = $media->metadata;

        return response()->json([
            'labels' => $metadata?->vision_labels ?? [],
            'status' => $metadata?->vision_status,
        ]);
    }

    /**
     * Re-run vision analysis on a media.
     */
    public function reanalyze(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        if ($media->type !== 'photo') {
            return response()->json(['message' => 'Only photos can be analyzed'], 422);
        }

        // Delete existing detected faces
        $media->detectedFaces()->delete();

        // Reset vision status
        if ($media->metadata) {
            $media->metadata->update([
                'vision_status' => 'pending',
                'vision_labels' => null,
                'vision_error' => null,
                'vision_faces_count' => 0,
                'vision_processed_at' => null,
            ]);
        }

        // Re-dispatch the analysis job
        AnalyzeMediaWithVision::dispatch($media);

        return response()->json(['message' => 'Analysis re-queued']);
    }

    /**
     * Get vision processing status for a media.
     */
    public function status(Media $media): JsonResponse
    {
        $this->authorizeMedia($media);

        $metadata = $media->metadata;

        return response()->json([
            'status' => $metadata?->vision_status,
            'provider' => $metadata?->vision_provider,
            'processed_at' => $metadata?->vision_processed_at,
            'error' => $metadata?->vision_error,
            'faces_count' => $metadata?->vision_faces_count ?? 0,
        ]);
    }

    /**
     * Ensure the authenticated user owns the media.
     */
    private function authorizeMedia(Media $media): void
    {
        if ($media->user_id !== auth()->id()) {
            abort(403);
        }
    }
}
