<?php

namespace App\Contracts;

interface VisionServiceInterface
{
    /**
     * Detect faces in an image.
     *
     * @param string $imagePath Absolute local file path
     * @return array{faces: array<int, array{
     *   bounding_box: array{x: float, y: float, width: float, height: float},
     *   confidence: float,
     *   landmarks: array|null,
     *   emotions: array|null,
     *   angles: array|null,
     * }>}
     */
    public function detectFaces(string $imagePath): array;

    /**
     * Detect labels/objects in an image.
     *
     * @param string $imagePath Absolute local file path
     * @return array{labels: array<int, array{
     *   name: string,
     *   score: float,
     *   topicality: float|null,
     * }>}
     */
    public function detectLabels(string $imagePath): array;

    /**
     * Run full analysis (faces + labels) in a single call.
     *
     * @param string $imagePath
     * @return array{faces: array, labels: array}
     */
    public function analyze(string $imagePath): array;

    /**
     * Whether this provider is properly configured and available.
     */
    public function isAvailable(): bool;

    /**
     * Get the provider name string (e.g., 'google', 'local').
     */
    public function getProviderName(): string;
}
