<?php

namespace App\Jobs\Concerns;

use App\Services\S3Service;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

trait DownloadsMediaToTemp
{
    /**
     * Download the media file from storage to a temporary local path.
     *
     * Expects $this->media to be set (App\Models\Media instance).
     */
    protected function downloadFileToTemp(S3Service $s3Service): ?string
    {
        try {
            $disk = Storage::disk($s3Service->getDisk());

            if (! $disk->exists($this->media->file_path)) {
                Log::error(class_basename($this) . ': File does not exist in storage', [
                    'media_id' => $this->media->id,
                    'file_path' => $this->media->file_path,
                ]);

                return null;
            }

            $extension = pathinfo($this->media->original_name, PATHINFO_EXTENSION) ?: 'tmp';
            $tempPath = sys_get_temp_dir() . '/media_' . $this->media->id . '_' . uniqid() . '.' . $extension;

            $contents = $disk->get($this->media->file_path);
            file_put_contents($tempPath, $contents);

            return $tempPath;
        } catch (\Exception $e) {
            Log::error(class_basename($this) . ': Failed to download file to temp', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
