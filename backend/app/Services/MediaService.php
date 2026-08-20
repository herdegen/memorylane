<?php

namespace App\Services;

use App\Jobs\AnalyzeMediaWithVision;
use App\Jobs\GenerateMediaConversions;
use App\Jobs\ProcessUploadedMedia;
use App\Models\Media;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;

class MediaService
{
    protected S3Service $s3Service;

    public function __construct(S3Service $s3Service)
    {
        $this->s3Service = $s3Service;
    }
    /**
     * Get paginated media with filters.
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedMedia(array $filters = [], int $perPage = 24, ?string $userId = null): LengthAwarePaginator
    {
        $media = $this->buildFilteredQuery($filters, $userId)
            ->with(['user', 'conversions', 'metadata', 'tags'])
            ->paginate($perPage);

        $this->hydrateSignedUrls($media->getCollection());

        return $media;
    }

    /**
     * URL applicative PROTÉGÉE d'un fichier média : le navigateur passe par
     * /media/{media}/file/{conversion?} (session + policy `view` vérifiées à
     * CHAQUE chargement) qui redirige vers une présignée S3 très courte.
     * Une URL copiée-collée est donc inutilisable sans être connecté.
     */
    public function fileUrl(Media $media, ?string $conversionName = null): string
    {
        return route('media.file', array_filter([
            'media' => $media->id,
            'conversion' => $conversionName,
        ]));
    }

    /**
     * Hydrate `->url` sur chaque média ET sur chacune de ses conversions
     * chargées. Point unique de génération des URLs exposées au navigateur :
     * URLs applicatives protégées (cf. fileUrl), plus jamais de présignées
     * longues dans les pages.
     *
     * @param iterable<Media> $mediaItems
     */
    public function hydrateSignedUrls(iterable $mediaItems): void
    {
        foreach ($mediaItems as $item) {
            $item->url = $this->fileUrl($item);

            if ($item->relationLoaded('conversions')) {
                foreach ($item->conversions as $conversion) {
                    $conversion->url = $this->fileUrl($item, $conversion->conversion_name);
                }
            }
        }
    }

    /**
     * Variante « album partagé par lien secret » : URLs passant par la route
     * token (pas de session requise, mais le token EST le droit d'accès et
     * chaque fichier est vérifié comme appartenant à l'album).
     *
     * @param iterable<Media> $mediaItems
     */
    public function hydrateSharedUrls(string $token, iterable $mediaItems): void
    {
        foreach ($mediaItems as $item) {
            $item->url = route('albums.shared.file', ['token' => $token, 'media' => $item->id]);

            if ($item->relationLoaded('conversions')) {
                foreach ($item->conversions as $conversion) {
                    $conversion->url = route('albums.shared.file', [
                        'token' => $token,
                        'media' => $item->id,
                        'conversion' => $conversion->conversion_name,
                    ]);
                }
            }
        }
    }

    /**
     * URL de la meilleure vignette disponible : la première conversion de
     * $preferred qui existe, sinon l'original.
     *
     * Par défaut : URL applicative protégée. `presigned: true` force une
     * présignée S3 (uniquement pour les contextes SANS session : emails).
     */
    public function thumbnailUrl(Media $media, array $preferred = ['small', 'thumbnail'], int $expirationMinutes = 60, bool $presigned = false): string
    {
        $conversion = null;
        foreach ($preferred as $name) {
            $conversion = $media->conversions->firstWhere('conversion_name', $name);
            if ($conversion) {
                break;
            }
        }

        if ($presigned) {
            return $this->getSignedUrl($media, $conversion?->file_path, $expirationMinutes);
        }

        return $this->fileUrl($media, $conversion?->conversion_name);
    }

    /**
     * IDs de tous les médias correspondant aux filtres, sans pagination —
     * pour un « tout sélectionner » qui couvre aussi les pages non encore
     * chargées dans la galerie (infinite scroll).
     *
     * @return array<int,string>
     */
    public function getFilteredMediaIds(array $filters = [], ?string $userId = null): array
    {
        return $this->buildFilteredQuery($filters, $userId)
            ->pluck('id')
            ->all();
    }

    /**
     * Construit la requête de galerie (scopée au propriétaire + filtres),
     * partagée par la liste paginée et le « tout sélectionner ». Ne charge
     * aucune relation ni URL : à compléter selon le besoin de l'appelant.
     */
    protected function buildFilteredQuery(array $filters = [], ?string $userId = null): Builder
    {
        // Galerie privée : ne renvoyer que les médias du propriétaire.
        $userId = $userId ?? auth()->id();

        $query = Media::where('user_id', $userId)
            // Les vidéos sources découpées sont masquées de la galerie (leurs
            // clips les remplacent) ; elles restent accessibles depuis un clip.
            ->where('is_source', false)
            ->orderBy('taken_at', 'desc')
            ->orderBy('uploaded_at', 'desc');

        // Filter by type if provided
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Filter by tags if provided
        if (isset($filters['tags']) && !empty($filters['tags'])) {
            $tagIds = is_array($filters['tags']) ? $filters['tags'] : [$filters['tags']];
            $query->whereHas('tags', function ($q) use ($tagIds) {
                $q->whereIn('tags.id', $tagIds);
            });
        }

        // Search by name if provided
        if (isset($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }

        // Video-specific filters
        if (isset($filters['duration_min'])) {
            $query->where('duration', '>=', (int) $filters['duration_min']);
        }
        if (isset($filters['duration_max'])) {
            $query->where('duration', '<=', (int) $filters['duration_max']);
        }
        if (isset($filters['resolution'])) {
            $minHeight = match ($filters['resolution']) {
                '4k'    => 2160,
                '1080p' => 1080,
                '720p'  => 720,
                default => 0,
            };
            if ($minHeight > 0) {
                $query->where('height', '>=', $minHeight);
            }
        }
        if (isset($filters['video_codec'])) {
            $query->where('video_codec', $filters['video_codec']);
        }

        return $query;
    }

    /**
     * Upload a media file and create database record.
     *
     * @param UploadedFile $file
     * @param string $userId
     * @return Media
     * @throws \Exception
     */
    public function uploadMedia(UploadedFile $file, string $userId): Media
    {
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();
        $extension = strtolower($file->getClientOriginalExtension());

        // Determine visibility (public for local/public disks, private for S3)
        $visibility = in_array($this->s3Service->getDisk(), ['local', 'public']) ? 'public' : 'private';

        $isHeic = in_array($extension, ['heic', 'heif'])
            || in_array($mimeType, ['image/heic', 'image/heif']);

        if ($isHeic) {
            // Photos iPhone : on convertit en JPEG (affichable partout) avant
            // stockage. L'original HEIC n'est pas conservé.
            [$jpegPath, $width, $height] = $this->convertHeicToJpeg($file);
            try {
                $type = 'photo';
                $mimeType = 'image/jpeg';
                $size = filesize($jpegPath) ?: $size;
                $contentHash = @hash_file('sha256', $jpegPath) ?: null;
                $filePath = $this->s3Service->generateFilePath('photo', 'jpg');
                $this->s3Service->putFile($jpegPath, $filePath, $visibility);
                $dimensions = ['width' => $width, 'height' => $height];
            } finally {
                @unlink($jpegPath);
            }
        } else {
            $type = $this->determineMediaType($mimeType);
            $filePath = $this->s3Service->generateFilePath($type, $extension);
            $this->s3Service->upload($file, $filePath, $visibility);
            $dimensions = $this->getImageDimensions($file, $mimeType);
            // Empreinte de contenu (dédup fiable, indépendante du nom)
            $contentHash = @hash_file('sha256', $file->getRealPath()) ?: null;
        }

        // Create media record
        $media = Media::create([
            'user_id' => $userId,
            'type' => $type,
            'original_name' => $originalName,
            'file_path' => $filePath,
            'content_hash' => $contentHash,
            'mime_type' => $mimeType,
            'size' => $size,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'uploaded_at' => now(),
        ]);

        $this->dispatchProcessingJobs($media);

        return $media;
    }

    /**
     * Crée un Media à partir d'un objet DÉJÀ présent sur S3 (upload multipart
     * direct navigateur -> Scaleway). La clé est générée côté serveur, donc
     * fiable. Réutilise la même chaîne de jobs que l'upload classique.
     *
     * @param  array<string, mixed>  $extra  Attributs supplémentaires (width, height…)
     */
    public function createFromS3Object(
        string $userId,
        string $key,
        string $originalName,
        string $mimeType,
        int $size,
        ?string $type = null,
        array $extra = [],
    ): Media {
        $type = $type ?? $this->determineMediaType($mimeType);

        $media = Media::create(array_merge([
            'user_id'       => $userId,
            'type'          => $type,
            'original_name' => $originalName,
            'file_path'     => $key,
            'mime_type'     => $mimeType,
            'size'          => $size,
            'uploaded_at'   => now(),
        ], $extra));

        $this->dispatchProcessingJobs($media);

        return $media;
    }

    /**
     * Chaîne de traitement post-upload commune (métadonnées, conversions,
     * vision). Les métadonnées vidéo sont extraites par GenerateMediaConversions
     * sur le fichier téléchargé (pas de job séparé, pas de double download S3).
     */
    protected function dispatchProcessingJobs(Media $media): void
    {
        ProcessUploadedMedia::dispatch($media);
        GenerateMediaConversions::dispatch($media);

        if (config('vision.enabled')) {
            AnalyzeMediaWithVision::dispatch($media)->delay(now()->addSeconds(5));
        }
    }

    /**
     * Delete a media file from storage and database.
     *
     * @param Media $media
     * @return void
     * @throws \Exception
     */
    public function deleteMedia(Media $media): void
    {
        // Soft delete pur : les fichiers S3 (original + conversions) sont
        // conservés pour que la restauration depuis la corbeille (admin)
        // fonctionne. La purge S3 a lieu au forceDelete (event du modèle).
        $media->delete();
    }

    /**
     * Purge tous les fichiers S3 d'un média : l'original ET ses conversions
     * (miniatures). `deleteMedia()` / le soft delete n'effacent que l'original,
     * laissant les miniatures orphelines ; à utiliser lors d'une suppression
     * DÉFINITIVE (force delete), où la ligne `media_conversions` disparaît par
     * cascade mais le fichier S3, lui, resterait.
     */
    public function purgeStorageFiles(Media $media): void
    {
        if ($media->file_path) {
            $this->s3Service->delete($media->file_path);
        }

        foreach ($media->conversions as $conversion) {
            if ($conversion->file_path) {
                $this->s3Service->delete($conversion->file_path);
            }
        }
    }

    /**
     * Get a signed URL for a media file, or one of its conversions.
     *
     * @param Media $media
     * @param string|null $conversionPath Path of a conversion file; defaults to the original
     * @param int $expirationMinutes
     * @return string
     */
    public function getSignedUrl(Media $media, ?string $conversionPath = null, int $expirationMinutes = 60): string
    {
        return $this->s3Service->getTemporaryUrl($conversionPath ?? $media->file_path, $expirationMinutes);
    }

    /**
     * Applique une position GPS à un lot de médias, par upserts groupés
     * (media_id est unique dans media_metadata). L'altitude est TOUJOURS
     * écrite : quand la nouvelle position ne la fournit pas, l'ancienne
     * n'aurait plus de sens (elle décrivait d'autres coordonnées).
     *
     * @param iterable<int,string> $mediaIds
     */
    public function bulkSetGeolocation(iterable $mediaIds, float $latitude, float $longitude, ?float $altitude = null): void
    {
        $now = now();

        collect($mediaIds)
            ->map(fn ($mediaId) => [
                'media_id' => $mediaId,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'altitude' => $altitude,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->chunk(500)
            ->each(fn ($rows) => \App\Models\MediaMetadata::upsert(
                $rows->all(),
                ['media_id'],
                ['latitude', 'longitude', 'altitude', 'updated_at'],
            ));
    }

    /**
     * Get a download URL for a media file.
     *
     * @param Media $media
     * @return string
     */
    public function getDownloadUrl(Media $media): string
    {
        return $this->s3Service->getDownloadUrl($media->file_path, $media->original_name);
    }

    /**
     * Determine media type from MIME type.
     *
     * @param string $mimeType
     * @return string
     */
    public function determineMediaType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'photo';
        } elseif (str_starts_with($mimeType, 'video/')) {
            return 'video';
        } else {
            return 'document';
        }
    }

    /**
     * Get image dimensions if the file is an image.
     *
     * @param UploadedFile $file
     * @param string $mimeType
     * @return array
     */
    /**
     * Convertit un fichier HEIC/HEIF en JPEG (via Imagick + libheif) dans un
     * fichier temporaire. Renvoie [chemin_jpeg, largeur, hauteur].
     *
     * @throws \Exception si la décode HEIC échoue (libheif absent, fichier corrompu)
     */
    protected function convertHeicToJpeg(UploadedFile $file): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'heic_') . '.jpg';

        $image = new \Imagick();
        $image->readImage($file->getRealPath());
        $image->setIteratorIndex(0); // première image (ignore les frames Live Photo)
        $image->setImageFormat('jpeg');
        $image->setImageCompressionQuality(90);
        $image->autoOrient();
        $width = $image->getImageWidth();
        $height = $image->getImageHeight();
        $image->writeImage($tmp);
        $image->clear();

        return [$tmp, $width, $height];
    }

    protected function getImageDimensions(UploadedFile $file, string $mimeType): array
    {
        $dimensions = [
            'width' => null,
            'height' => null,
        ];

        if (str_starts_with($mimeType, 'image/')) {
            try {
                $imageSize = getimagesize($file->getRealPath());
                $dimensions['width'] = $imageSize[0] ?? null;
                $dimensions['height'] = $imageSize[1] ?? null;
            } catch (\Exception $e) {
                // Continue without dimensions
            }
        }

        return $dimensions;
    }
}
