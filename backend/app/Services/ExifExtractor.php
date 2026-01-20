<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;

class ExifExtractor
{
    /**
     * Extract EXIF data from an image file.
     *
     * @param UploadedFile|string $file Either an UploadedFile or path to file
     * @return array Array containing extracted EXIF data
     */
    public function extract(UploadedFile|string $file): array
    {
        $filePath = $file instanceof UploadedFile ? $file->getRealPath() : $file;

        if (!file_exists($filePath)) {
            Log::warning('ExifExtractor: File does not exist', ['path' => $filePath]);
            return $this->getEmptyExifData();
        }

        if (!$this->isImageFile($filePath)) {
            return $this->getEmptyExifData();
        }

        try {
            $exifData = @exif_read_data($filePath, 0, true);

            if ($exifData === false) {
                return $this->getEmptyExifData();
            }

            return $this->parseExifData($exifData);
        } catch (\Exception $e) {
            Log::warning('ExifExtractor: Failed to extract EXIF data', [
                'error' => $e->getMessage(),
                'file' => $filePath
            ]);
            return $this->getEmptyExifData();
        }
    }

    /**
     * Parse raw EXIF data into structured format.
     *
     * @param array $exifData
     * @return array
     */
    protected function parseExifData(array $exifData): array
    {
        return [
            'exif_data' => $this->sanitizeExifData($exifData),
            'camera_make' => $this->getCameraMake($exifData),
            'camera_model' => $this->getCameraModel($exifData),
            'iso' => $this->getIso($exifData),
            'aperture' => $this->getAperture($exifData),
            'shutter_speed' => $this->getShutterSpeed($exifData),
            'focal_length' => $this->getFocalLength($exifData),
            'latitude' => $this->getLatitude($exifData),
            'longitude' => $this->getLongitude($exifData),
            'altitude' => $this->getAltitude($exifData),
            'taken_at' => $this->getDateTaken($exifData),
        ];
    }

    /**
     * Get camera make from EXIF data.
     */
    protected function getCameraMake(array $exifData): ?string
    {
        return $exifData['IFD0']['Make']
            ?? $exifData['EXIF']['Make']
            ?? null;
    }

    /**
     * Get camera model from EXIF data.
     */
    protected function getCameraModel(array $exifData): ?string
    {
        return $exifData['IFD0']['Model']
            ?? $exifData['EXIF']['Model']
            ?? null;
    }

    /**
     * Get ISO value from EXIF data.
     */
    protected function getIso(array $exifData): ?int
    {
        $iso = $exifData['EXIF']['ISOSpeedRatings']
            ?? $exifData['EXIF']['PhotographicSensitivity']
            ?? null;

        return $iso ? (int) $iso : null;
    }

    /**
     * Get aperture value from EXIF data.
     */
    protected function getAperture(array $exifData): ?float
    {
        $aperture = $exifData['EXIF']['FNumber']
            ?? $exifData['COMPUTED']['ApertureFNumber']
            ?? null;

        if (!$aperture) {
            return null;
        }

        if (is_string($aperture) && str_contains($aperture, '/')) {
            return $this->evaluateFraction($aperture);
        }

        return (float) $aperture;
    }

    /**
     * Get shutter speed from EXIF data.
     */
    protected function getShutterSpeed(array $exifData): ?string
    {
        return $exifData['EXIF']['ExposureTime']
            ?? $exifData['COMPUTED']['ShutterSpeed']
            ?? null;
    }

    /**
     * Get focal length from EXIF data.
     */
    protected function getFocalLength(array $exifData): ?int
    {
        $focalLength = $exifData['EXIF']['FocalLength'] ?? null;

        if (!$focalLength) {
            return null;
        }

        if (is_string($focalLength) && str_contains($focalLength, '/')) {
            return (int) round($this->evaluateFraction($focalLength));
        }

        return (int) $focalLength;
    }

    /**
     * Get latitude from GPS EXIF data.
     */
    protected function getLatitude(array $exifData): ?float
    {
        if (!isset($exifData['GPS']['GPSLatitude'], $exifData['GPS']['GPSLatitudeRef'])) {
            return null;
        }

        return $this->convertGpsCoordinate(
            $exifData['GPS']['GPSLatitude'],
            $exifData['GPS']['GPSLatitudeRef']
        );
    }

    /**
     * Get longitude from GPS EXIF data.
     */
    protected function getLongitude(array $exifData): ?float
    {
        if (!isset($exifData['GPS']['GPSLongitude'], $exifData['GPS']['GPSLongitudeRef'])) {
            return null;
        }

        return $this->convertGpsCoordinate(
            $exifData['GPS']['GPSLongitude'],
            $exifData['GPS']['GPSLongitudeRef']
        );
    }

    /**
     * Get altitude from GPS EXIF data.
     */
    protected function getAltitude(array $exifData): ?float
    {
        if (!isset($exifData['GPS']['GPSAltitude'])) {
            return null;
        }

        $altitude = $exifData['GPS']['GPSAltitude'];

        if (is_string($altitude) && str_contains($altitude, '/')) {
            $altitude = $this->evaluateFraction($altitude);
        }

        $altitudeRef = $exifData['GPS']['GPSAltitudeRef'] ?? 0;

        return $altitudeRef == 1 ? -$altitude : $altitude;
    }

    /**
     * Get date taken from EXIF data.
     */
    protected function getDateTaken(array $exifData): ?string
    {
        $dateString = $exifData['EXIF']['DateTimeOriginal']
            ?? $exifData['EXIF']['DateTime']
            ?? $exifData['IFD0']['DateTime']
            ?? null;

        if (!$dateString) {
            return null;
        }

        try {
            $date = \DateTime::createFromFormat('Y:m:d H:i:s', $dateString);
            return $date ? $date->format('Y-m-d H:i:s') : null;
        } catch (\Exception $e) {
            Log::warning('ExifExtractor: Failed to parse date', [
                'date_string' => $dateString,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Convert GPS coordinates from DMS to decimal degrees.
     *
     * @param array $coordinate Array of degrees, minutes, seconds
     * @param string $hemisphere N, S, E, or W
     * @return float
     */
    protected function convertGpsCoordinate(array $coordinate, string $hemisphere): float
    {
        $degrees = $this->evaluateFraction($coordinate[0]);
        $minutes = $this->evaluateFraction($coordinate[1]);
        $seconds = $this->evaluateFraction($coordinate[2]);

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if (in_array($hemisphere, ['S', 'W'])) {
            $decimal *= -1;
        }

        return round($decimal, 8);
    }

    /**
     * Evaluate a fraction string (e.g., "1/2") to a float.
     *
     * @param string|int|float $fraction
     * @return float
     */
    protected function evaluateFraction(string|int|float $fraction): float
    {
        if (is_numeric($fraction)) {
            return (float) $fraction;
        }

        if (str_contains($fraction, '/')) {
            [$numerator, $denominator] = explode('/', $fraction);

            if ($denominator == 0) {
                return 0;
            }

            return (float) $numerator / (float) $denominator;
        }

        return (float) $fraction;
    }

    /**
     * Check if file is an image that can contain EXIF data.
     */
    protected function isImageFile(string $filePath): bool
    {
        $mimeType = mime_content_type($filePath);

        $supportedTypes = [
            'image/jpeg',
            'image/jpg',
            'image/tiff',
        ];

        return in_array($mimeType, $supportedTypes);
    }

    /**
     * Sanitize EXIF data by removing binary data and large thumbnails.
     */
    protected function sanitizeExifData(array $exifData): array
    {
        $sanitized = $exifData;

        $keysToRemove = [
            'THUMBNAIL',
            'MakerNote',
            'UserComment',
        ];

        foreach ($keysToRemove as $key) {
            if (isset($sanitized[$key])) {
                unset($sanitized[$key]);
            }

            foreach ($sanitized as $section => $values) {
                if (is_array($values) && isset($values[$key])) {
                    unset($sanitized[$section][$key]);
                }
            }
        }

        return $sanitized;
    }

    /**
     * Get empty EXIF data structure.
     */
    protected function getEmptyExifData(): array
    {
        return [
            'exif_data' => null,
            'camera_make' => null,
            'camera_model' => null,
            'iso' => null,
            'aperture' => null,
            'shutter_speed' => null,
            'focal_length' => null,
            'latitude' => null,
            'longitude' => null,
            'altitude' => null,
            'taken_at' => null,
        ];
    }
}
