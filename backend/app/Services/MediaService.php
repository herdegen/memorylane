<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Pagination\LengthAwarePaginator;

class MediaService
{
    /**
     * Get paginated media with filters.
     *
     * @param array $filters
     * @param int $perPage
     * @return LengthAwarePaginator
     */
    public function getPaginatedMedia(array $filters = [], int $perPage = 24): LengthAwarePaginator
    {
        $query = Media::with(['user'])
            ->orderBy('taken_at', 'desc')
            ->orderBy('uploaded_at', 'desc');

        // Filter by type if provided
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        // Search by name if provided
        if (isset($filters['search'])) {
            $query->where('original_name', 'like', '%' . $filters['search'] . '%');
        }

        $media = $query->paginate($perPage);

        // Add signed URLs for each media item
        $media->getCollection()->transform(function ($item) {
            $item->url = $this->getSignedUrl($item);
            return $item;
        });

        return $media;
    }

    /**
     * Upload a media file and create database record.
     *
     * @param UploadedFile $file
     * @param int $userId
     * @return Media
     * @throws \Exception
     */
    public function uploadMedia(UploadedFile $file, int $userId): Media
    {
        $originalName = $file->getClientOriginalName();
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        // Determine media type
        $type = $this->determineMediaType($mimeType);

        // Generate unique file path
        $filePath = $this->generateFilePath($file, $type);

        // Upload to storage
        $this->uploadToStorage($file, $filePath);

        // Get image dimensions if it's an image
        $dimensions = $this->getImageDimensions($file, $mimeType);

        // Create media record
        $media = Media::create([
            'user_id' => $userId,
            'type' => $type,
            'original_name' => $originalName,
            'file_path' => $filePath,
            'mime_type' => $mimeType,
            'size' => $size,
            'width' => $dimensions['width'] ?? null,
            'height' => $dimensions['height'] ?? null,
            'uploaded_at' => now(),
        ]);

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
        if (Storage::disk('scaleway')->exists($media->file_path)) {
            Storage::disk('scaleway')->delete($media->file_path);
        }

        // Soft delete the media record
        $media->delete();
    }

    /**
     * Get a signed URL for a media file.
     *
     * @param Media $media
     * @param int $expirationMinutes
     * @return string
     */
    public function getSignedUrl(Media $media, int $expirationMinutes = 60): string
    {
        return Storage::disk('scaleway')->temporaryUrl(
            $media->file_path,
            now()->addMinutes($expirationMinutes)
        );
    }

    /**
     * Get a download URL for a media file.
     *
     * @param Media $media
     * @return string
     */
    public function getDownloadUrl(Media $media): string
    {
        return Storage::disk('scaleway')->temporaryUrl(
            $media->file_path,
            now()->addMinutes(5),
            [
                'ResponseContentDisposition' => 'attachment; filename="' . $media->original_name . '"'
            ]
        );
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
     * Generate a unique file path for storage.
     *
     * @param UploadedFile $file
     * @param string $type
     * @return string
     */
    protected function generateFilePath(UploadedFile $file, string $type): string
    {
        $extension = $file->getClientOriginalExtension();
        $filename = Str::uuid() . '.' . $extension;
        return "media/{$type}s/" . date('Y/m') . "/{$filename}";
    }

    /**
     * Upload file to storage.
     *
     * @param UploadedFile $file
     * @param string $filePath
     * @return void
     * @throws \Exception
     */
    protected function uploadToStorage(UploadedFile $file, string $filePath): void
    {
        try {
            Storage::disk('scaleway')->putFileAs(
                dirname($filePath),
                $file,
                basename($filePath),
                'private'
            );
        } catch (\Exception $e) {
            throw new \Exception('Failed to upload file to storage: ' . $e->getMessage());
        }
    }

    /**
     * Get image dimensions if the file is an image.
     *
     * @param UploadedFile $file
     * @param string $mimeType
     * @return array
     */
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
