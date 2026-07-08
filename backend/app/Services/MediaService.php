<?php

namespace App\Services;

use App\Jobs\AnalyzeMediaWithVision;
use App\Jobs\GenerateMediaConversions;
use App\Jobs\ProcessUploadedMedia;
use App\Models\Media;
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
        // Galerie privée : ne renvoyer que les médias du propriétaire.
        $userId = $userId ?? auth()->id();

        $query = Media::with(['user', 'conversions', 'metadata', 'tags'])
            ->where('user_id', $userId)
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

        $media = $query->paginate($perPage);

        // Add signed URLs for each media item and its conversions
        $media->getCollection()->transform(function ($item) {
            $item->url = $this->s3Service->getTemporaryUrl($item->file_path);

            // Add signed URLs for conversions
            if ($item->conversions) {
                $item->conversions->transform(function ($conversion) {
                    $conversion->url = $this->s3Service->getTemporaryUrl($conversion->file_path);
                    return $conversion;
                });
            }

            return $item;
        });

        return $media;
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

        // Dispatch background jobs for processing.
        // Les métadonnées vidéo sont extraites par GenerateMediaConversions
        // sur le fichier téléchargé (pas de job séparé, pas de double download S3).
        ProcessUploadedMedia::dispatch($media);
        GenerateMediaConversions::dispatch($media);

        // Dispatch Vision AI analysis if enabled
        if (config('vision.enabled')) {
            AnalyzeMediaWithVision::dispatch($media)->delay(now()->addSeconds(5));
        }

        return $media;
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
        // Delete from storage
        $this->s3Service->delete($media->file_path);

        // Soft delete the media record
        $media->delete();
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
