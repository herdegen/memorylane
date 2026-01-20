<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class S3Service
{
    protected string $disk;

    public function __construct()
    {
        $this->disk = config('filesystems.default', 'scaleway');
    }

    /**
     * Upload a file to S3 storage.
     *
     * @param UploadedFile $file
     * @param string $path
     * @param string $visibility
     * @return string The path where the file was stored
     * @throws \Exception
     */
    public function upload(UploadedFile $file, string $path, string $visibility = 'private'): string
    {
        try {
            Storage::disk($this->disk)->putFileAs(
                dirname($path),
                $file,
                basename($path),
                $visibility
            );

            return $path;
        } catch (\Exception $e) {
            throw new \Exception('Failed to upload file to storage: ' . $e->getMessage());
        }
    }

    /**
     * Delete a file from S3 storage.
     *
     * @param string $path
     * @return bool
     */
    public function delete(string $path): bool
    {
        if ($this->exists($path)) {
            return Storage::disk($this->disk)->delete($path);
        }

        return false;
    }

    /**
     * Check if a file exists in S3 storage.
     *
     * @param string $path
     * @return bool
     */
    public function exists(string $path): bool
    {
        return Storage::disk($this->disk)->exists($path);
    }

    /**
     * Get a temporary signed URL for a file.
     *
     * @param string $path
     * @param int $expirationMinutes
     * @param array $options
     * @return string
     */
    public function getTemporaryUrl(string $path, int $expirationMinutes = 60, array $options = []): string
    {
        return Storage::disk($this->disk)->temporaryUrl(
            $path,
            now()->addMinutes($expirationMinutes),
            $options
        );
    }

    /**
     * Get a download URL with proper headers.
     *
     * @param string $path
     * @param string $filename
     * @param int $expirationMinutes
     * @return string
     */
    public function getDownloadUrl(string $path, string $filename, int $expirationMinutes = 5): string
    {
        return $this->getTemporaryUrl($path, $expirationMinutes, [
            'ResponseContentDisposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    /**
     * Generate a unique file path for storage.
     *
     * @param string $type Media type (photo, video, document)
     * @param string $extension File extension
     * @return string
     */
    public function generateFilePath(string $type, string $extension): string
    {
        $filename = Str::uuid() . '.' . $extension;
        return "media/{$type}s/" . date('Y/m') . "/{$filename}";
    }

    /**
     * Get the size of a file in storage.
     *
     * @param string $path
     * @return int File size in bytes
     */
    public function size(string $path): int
    {
        return Storage::disk($this->disk)->size($path);
    }

    /**
     * Get the MIME type of a file in storage.
     *
     * @param string $path
     * @return string|false
     */
    public function mimeType(string $path): string|false
    {
        return Storage::disk($this->disk)->mimeType($path);
    }

    /**
     * Get the public URL of a file (for public files).
     *
     * @param string $path
     * @return string
     */
    public function url(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Copy a file to a new location.
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function copy(string $from, string $to): bool
    {
        return Storage::disk($this->disk)->copy($from, $to);
    }

    /**
     * Move a file to a new location.
     *
     * @param string $from
     * @param string $to
     * @return bool
     */
    public function move(string $from, string $to): bool
    {
        return Storage::disk($this->disk)->move($from, $to);
    }

    /**
     * Get the disk name being used.
     *
     * @return string
     */
    public function getDisk(): string
    {
        return $this->disk;
    }

    /**
     * Set the disk to use.
     *
     * @param string $disk
     * @return self
     */
    public function setDisk(string $disk): self
    {
        $this->disk = $disk;
        return $this;
    }
}
