<?php

namespace App\Http\Controllers;

use App\Models\UploadSession;
use App\Services\MediaService;
use App\Services\S3Service;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Upload multipart direct navigateur -> Scaleway S3 (gros fichiers / vidéos).
 *
 * Le fichier ne transite jamais par PHP/nginx : le navigateur téléverse chaque
 * part directement sur S3 via une URL présignée, puis notifie le backend qui
 * finalise l'upload et crée le Media. Contourne les limites 2 Go et permet la
 * reprise part par part.
 */
class UploadController extends Controller
{
    /** Taille d'une part (100 Mo). Min S3 = 5 Mo (sauf dernière part). */
    public const PART_SIZE = 104857600;

    /** Types MIME autorisés pour l'upload direct. */
    public const ALLOWED_MIMES = [
        'video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-matroska', 'video/webm',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
    ];

    public function __construct(
        protected S3Service $s3,
        protected MediaService $mediaService,
    ) {}

    /** Plafond configurable (défaut 20 Go). */
    protected function maxBytes(): int
    {
        return (int) config('media.upload_max_bytes');
    }

    /**
     * Démarre l'upload multipart : crée l'upload S3 + la session de suivi.
     */
    public function initiate(Request $request)
    {
        $validated = $request->validate([
            'original_name' => 'required|string|max:255',
            'mime_type'     => 'required|string|in:' . implode(',', self::ALLOWED_MIMES),
            'size'          => 'required|integer|min:1|max:' . $this->maxBytes(),
        ]);

        $type = $this->mediaService->determineMediaType($validated['mime_type']);
        $ext = strtolower(pathinfo($validated['original_name'], PATHINFO_EXTENSION)) ?: 'bin';
        $key = $this->s3->generateFilePath($type, $ext);

        try {
            $uploadId = $this->s3->createMultipartUpload($key, $validated['mime_type']);
        } catch (\Throwable $e) {
            Log::error('Upload initiate failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => "Impossible d'initier l'upload."], 500);
        }

        $session = UploadSession::create([
            'user_id'       => auth()->id(),
            'upload_id'     => $uploadId,
            's3_key'        => $key,
            'original_name' => $validated['original_name'],
            'mime_type'     => $validated['mime_type'],
            'size'          => $validated['size'],
            'type'          => $type,
        ]);

        return response()->json([
            'upload_session_id' => $session->id,
            'part_size'         => self::PART_SIZE,
            'part_count'        => (int) ceil($validated['size'] / self::PART_SIZE),
        ], 201);
    }

    /**
     * État d'un upload en cours : parts déjà montées sur S3 (reprise).
     * Renvoie 404 si la session n'existe plus (upload à relancer de zéro).
     */
    public function status(Request $request)
    {
        $validated = $request->validate([
            'upload_session_id' => 'required|uuid',
        ]);

        $session = $this->ownedSession($validated['upload_session_id']);

        try {
            $uploaded = $this->s3->listUploadedParts($session->s3_key, $session->upload_id);
        } catch (\Throwable $e) {
            // Upload S3 disparu/expiré : la session locale est caduque.
            $session->delete();
            return response()->json(['error' => 'Upload introuvable, à relancer.'], 410);
        }

        return response()->json([
            'upload_session_id' => $session->id,
            'part_size'         => self::PART_SIZE,
            'part_count'        => (int) ceil($session->size / self::PART_SIZE),
            'original_name'     => $session->original_name,
            'size'              => $session->size,
            'uploaded_parts'    => $uploaded,
        ]);
    }

    /**
     * URL présignée pour téléverser une part donnée.
     */
    public function partUrl(Request $request)
    {
        $validated = $request->validate([
            'upload_session_id' => 'required|uuid',
            'part_number'       => 'required|integer|min:1|max:10000',
        ]);

        $session = $this->ownedSession($validated['upload_session_id']);

        $url = $this->s3->presignUploadPart($session->s3_key, $session->upload_id, $validated['part_number']);

        return response()->json(['url' => $url]);
    }

    /**
     * Finalise l'upload : assemble les parts et crée le Media.
     */
    public function complete(Request $request)
    {
        $validated = $request->validate([
            'upload_session_id'  => 'required|uuid',
            'parts'              => 'required|array|min:1',
            'parts.*.part_number' => 'required|integer|min:1',
            'parts.*.etag'       => 'required|string',
        ]);

        $session = $this->ownedSession($validated['upload_session_id']);

        $parts = collect($validated['parts'])
            ->sortBy('part_number')
            ->map(fn ($p) => ['PartNumber' => (int) $p['part_number'], 'ETag' => $p['etag']])
            ->values()
            ->all();

        try {
            $this->s3->completeMultipartUpload($session->s3_key, $session->upload_id, $parts);
        } catch (\Throwable $e) {
            Log::error('Upload complete failed', ['session' => $session->id, 'error' => $e->getMessage()]);
            return response()->json(['error' => "Échec de la finalisation de l'upload."], 500);
        }

        $media = $this->mediaService->createFromS3Object(
            userId: $session->user_id,
            key: $session->s3_key,
            originalName: $session->original_name,
            mimeType: $session->mime_type,
            size: $session->size,
            type: $session->type,
        );

        $session->delete();

        $media->url = $this->mediaService->fileUrl($media);

        return response()->json([
            'message' => 'Upload terminé',
            'media'   => $media,
        ], 201);
    }

    /**
     * Annule un upload en cours (libère les parts sur S3).
     */
    public function abort(Request $request)
    {
        $validated = $request->validate([
            'upload_session_id' => 'required|uuid',
        ]);

        $session = $this->ownedSession($validated['upload_session_id']);

        try {
            $this->s3->abortMultipartUpload($session->s3_key, $session->upload_id);
        } catch (\Throwable $e) {
            Log::warning('Upload abort failed', ['session' => $session->id, 'error' => $e->getMessage()]);
        }

        $session->delete();

        return response()->json(['message' => 'Upload annulé']);
    }

    /**
     * Récupère une session d'upload appartenant à l'utilisateur courant.
     */
    protected function ownedSession(string $id): UploadSession
    {
        return UploadSession::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();
    }
}
