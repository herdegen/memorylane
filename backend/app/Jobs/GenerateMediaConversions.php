<?php

namespace App\Jobs;

use App\Models\Media;
use App\Models\MediaConversion;
use App\Services\S3Service;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use FFMpeg\FFMpeg;
use FFMpeg\Coordinate\TimeCode;

class GenerateMediaConversions implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    /**
     * The number of times the job may be attempted.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     *
     * @var int
     */
    public $timeout = 600;

    /**
     * Conversion configurations for images.
     *
     * @var array
     */
    protected array $imageConversions = [
        'thumbnail' => ['width' => 150, 'height' => 150, 'fit' => 'cover'],
        'small' => ['width' => 400, 'height' => 400, 'fit' => 'contain'],
        'medium' => ['width' => 800, 'height' => 800, 'fit' => 'contain'],
        'large' => ['width' => 1600, 'height' => 1600, 'fit' => 'contain'],
    ];

    /**
     * Conversion configurations for videos.
     *
     * @var array
     */
    protected array $videoConversions = [
        'thumbnail' => ['width' => 150, 'height' => 150, 'second' => 1],
        'small' => ['width' => 640, 'height' => 480],
        'medium' => ['width' => 1280, 'height' => 720],
    ];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Media $media
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(S3Service $s3Service): void
    {
        try {
            Log::info('GenerateMediaConversions: Starting conversion generation', [
                'media_id' => $this->media->id,
                'type' => $this->media->type,
                'file_path' => $this->media->file_path,
            ]);

            // Generate conversions based on media type
            if ($this->media->type === 'photo') {
                $this->generateImageConversions($s3Service);
            } elseif ($this->media->type === 'video') {
                $this->generateVideoConversions($s3Service);
            }

            Log::info('GenerateMediaConversions: Conversion generation completed', [
                'media_id' => $this->media->id,
                'conversions_count' => $this->media->conversions()->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('GenerateMediaConversions: Conversion generation failed', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate image conversions (thumbnails, optimized versions).
     *
     * @param S3Service $s3Service
     * @return void
     */
    protected function generateImageConversions(S3Service $s3Service): void
    {
        try {
            // Download original file from S3
            $tempOriginalPath = $this->downloadFileToTemp($s3Service);
            if (!$tempOriginalPath) {
                Log::warning('GenerateMediaConversions: Failed to download image from S3', [
                    'media_id' => $this->media->id,
                ]);
                return;
            }

            // Create image manager
            $manager = new ImageManager(new Driver());

            foreach ($this->imageConversions as $conversionName => $config) {
                try {
                    // Read the image
                    $image = $manager->read($tempOriginalPath);

                    // Apply conversion based on fit type
                    if ($config['fit'] === 'cover') {
                        // Cover: resize and crop to exact dimensions
                        $image->cover($config['width'], $config['height']);
                    } else {
                        // Contain: resize maintaining aspect ratio
                        $image->scale(
                            width: $config['width'],
                            height: $config['height']
                        );
                    }

                    // Save to temporary file
                    $tempConversionPath = sys_get_temp_dir() . '/conversion_' . uniqid() . '.jpg';
                    $image->toJpeg(quality: 85)->save($tempConversionPath);

                    // Upload to S3
                    $conversionFilePath = $this->uploadConversionToS3(
                        $s3Service,
                        $tempConversionPath,
                        $conversionName
                    );

                    // Get dimensions and file size
                    $width = $image->width();
                    $height = $image->height();
                    $size = filesize($tempConversionPath);

                    // Save conversion record to database
                    MediaConversion::updateOrCreate(
                        [
                            'media_id' => $this->media->id,
                            'conversion_name' => $conversionName,
                        ],
                        [
                            'file_path' => $conversionFilePath,
                            'width' => $width,
                            'height' => $height,
                            'size' => $size,
                            'mime_type' => 'image/jpeg',
                        ]
                    );

                    // Clean up temporary conversion file
                    @unlink($tempConversionPath);

                    Log::info('GenerateMediaConversions: Image conversion generated', [
                        'media_id' => $this->media->id,
                        'conversion_name' => $conversionName,
                        'width' => $width,
                        'height' => $height,
                    ]);
                } catch (\Exception $e) {
                    Log::error('GenerateMediaConversions: Failed to generate image conversion', [
                        'media_id' => $this->media->id,
                        'conversion_name' => $conversionName,
                        'error' => $e->getMessage(),
                    ]);
                    // Continue with next conversion
                }
            }

            // Clean up temporary original file
            @unlink($tempOriginalPath);
        } catch (\Exception $e) {
            Log::error('GenerateMediaConversions: Image conversion process failed', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Generate video conversions (thumbnails, optimized versions).
     *
     * @param S3Service $s3Service
     * @return void
     */
    protected function generateVideoConversions(S3Service $s3Service): void
    {
        try {
            // Download original file from S3
            $tempOriginalPath = $this->downloadFileToTemp($s3Service);
            if (!$tempOriginalPath) {
                Log::warning('GenerateMediaConversions: Failed to download video from S3', [
                    'media_id' => $this->media->id,
                ]);
                return;
            }

            // Create FFMpeg instance
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries'  => env('FFMPEG_BINARIES', '/usr/bin/ffmpeg'),
                'ffprobe.binaries' => env('FFPROBE_BINARIES', '/usr/bin/ffprobe'),
                'timeout'          => 3600,
                'ffmpeg.threads'   => 12,
            ]);

            $video = $ffmpeg->open($tempOriginalPath);

            // Generate thumbnail from first second
            try {
                $thumbnailPath = sys_get_temp_dir() . '/video_thumbnail_' . uniqid() . '.jpg';
                $frame = $video->frame(TimeCode::fromSeconds(1));
                $frame->save($thumbnailPath);

                // Resize thumbnail using Intervention Image
                $manager = new ImageManager(new Driver());
                $image = $manager->read($thumbnailPath);
                $image->cover(150, 150);
                $image->toJpeg(quality: 85)->save($thumbnailPath);

                // Upload thumbnail to S3
                $thumbnailFilePath = $this->uploadConversionToS3(
                    $s3Service,
                    $thumbnailPath,
                    'thumbnail'
                );

                // Save thumbnail conversion record
                MediaConversion::updateOrCreate(
                    [
                        'media_id' => $this->media->id,
                        'conversion_name' => 'thumbnail',
                    ],
                    [
                        'file_path' => $thumbnailFilePath,
                        'width' => 150,
                        'height' => 150,
                        'size' => filesize($thumbnailPath),
                        'mime_type' => 'image/jpeg',
                    ]
                );

                @unlink($thumbnailPath);

                Log::info('GenerateMediaConversions: Video thumbnail generated', [
                    'media_id' => $this->media->id,
                ]);
            } catch (\Exception $e) {
                Log::error('GenerateMediaConversions: Failed to generate video thumbnail', [
                    'media_id' => $this->media->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Clean up temporary original file
            @unlink($tempOriginalPath);
        } catch (\Exception $e) {
            Log::error('GenerateMediaConversions: Video conversion process failed', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Download file from S3 to a temporary location.
     *
     * @param S3Service $s3Service
     * @return string|null The temporary file path, or null on failure
     */
    protected function downloadFileToTemp(S3Service $s3Service): ?string
    {
        try {
            $disk = Storage::disk($s3Service->getDisk());

            if (!$disk->exists($this->media->file_path)) {
                Log::error('GenerateMediaConversions: File does not exist in S3', [
                    'media_id' => $this->media->id,
                    'file_path' => $this->media->file_path,
                ]);
                return null;
            }

            // Get file extension from original filename
            $extension = pathinfo($this->media->original_name, PATHINFO_EXTENSION);

            // Create temporary file with proper extension
            $tempPath = sys_get_temp_dir() . '/media_' . $this->media->id . '_' . uniqid() . '.' . $extension;

            // Download file contents from S3
            $contents = $disk->get($this->media->file_path);

            // Write to temporary file
            file_put_contents($tempPath, $contents);

            return $tempPath;
        } catch (\Exception $e) {
            Log::error('GenerateMediaConversions: Failed to download file to temp', [
                'media_id' => $this->media->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Upload a conversion file to S3.
     *
     * @param S3Service $s3Service
     * @param string $localPath
     * @param string $conversionName
     * @return string The S3 file path
     */
    protected function uploadConversionToS3(S3Service $s3Service, string $localPath, string $conversionName): string
    {
        // Generate file path for conversion
        $extension = pathinfo($localPath, PATHINFO_EXTENSION);
        $directory = dirname($this->media->file_path);
        $filename = pathinfo($this->media->file_path, PATHINFO_FILENAME);
        $conversionPath = "{$directory}/{$filename}_{$conversionName}.{$extension}";

        // Determine visibility based on disk
        $visibility = in_array($s3Service->getDisk(), ['local', 'public']) ? 'public' : 'private';

        // Upload to S3
        $s3Service->putFile($localPath, $conversionPath, $visibility);

        return $conversionPath;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateMediaConversions: Job failed permanently', [
            'media_id' => $this->media->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
