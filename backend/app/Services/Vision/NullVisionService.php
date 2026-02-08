<?php

namespace App\Services\Vision;

use App\Contracts\VisionServiceInterface;

class NullVisionService implements VisionServiceInterface
{
    public function detectFaces(string $imagePath): array
    {
        return ['faces' => []];
    }

    public function detectLabels(string $imagePath): array
    {
        return ['labels' => []];
    }

    public function analyze(string $imagePath): array
    {
        return ['faces' => [], 'labels' => []];
    }

    public function isAvailable(): bool
    {
        return false;
    }

    public function getProviderName(): string
    {
        return 'null';
    }
}
