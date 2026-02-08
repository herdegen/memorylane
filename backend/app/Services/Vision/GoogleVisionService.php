<?php

namespace App\Services\Vision;

use App\Contracts\VisionServiceInterface;
use Google\Cloud\Vision\V1\ImageAnnotatorClient;
use Google\Cloud\Vision\V1\Feature\Type;
use Google\Cloud\Vision\V1\Likelihood;
use Illuminate\Support\Facades\Log;

class GoogleVisionService implements VisionServiceInterface
{
    private ?ImageAnnotatorClient $client = null;

    public function detectFaces(string $imagePath): array
    {
        $result = $this->annotateImage($imagePath, [Type::FACE_DETECTION]);

        return ['faces' => $this->parseFaceAnnotations($result, $imagePath)];
    }

    public function detectLabels(string $imagePath): array
    {
        $result = $this->annotateImage($imagePath, [Type::LABEL_DETECTION]);

        return ['labels' => $this->parseLabelAnnotations($result)];
    }

    public function analyze(string $imagePath): array
    {
        $result = $this->annotateImage($imagePath, [
            Type::FACE_DETECTION,
            Type::LABEL_DETECTION,
        ]);

        return [
            'faces' => $this->parseFaceAnnotations($result, $imagePath),
            'labels' => $this->parseLabelAnnotations($result),
        ];
    }

    public function isAvailable(): bool
    {
        $credentials = config('vision.google.credentials');

        return ! empty($credentials) && file_exists($credentials);
    }

    public function getProviderName(): string
    {
        return 'google';
    }

    /**
     * Send image to Google Cloud Vision API for annotation.
     */
    private function annotateImage(string $imagePath, array $features): \Google\Cloud\Vision\V1\AnnotateImageResponse
    {
        $client = $this->getClient();
        $imageContent = file_get_contents($imagePath);

        $response = $client->annotateImage($imageContent, $features);

        if ($response->getError() && $response->getError()->getCode() !== 0) {
            throw new \RuntimeException(
                'Google Vision API error: ' . $response->getError()->getMessage()
            );
        }

        return $response;
    }

    /**
     * Parse face annotations into normalized format.
     * Bounding boxes are converted to percentages of image dimensions.
     */
    private function parseFaceAnnotations(\Google\Cloud\Vision\V1\AnnotateImageResponse $response, string $imagePath): array
    {
        $faceAnnotations = $response->getFaceAnnotations();
        if (! $faceAnnotations || $faceAnnotations->count() === 0) {
            return [];
        }

        // Get image dimensions for percentage conversion
        $imageSize = @getimagesize($imagePath);
        $imageWidth = $imageSize[0] ?? 1;
        $imageHeight = $imageSize[1] ?? 1;

        $minConfidence = config('vision.thresholds.face_confidence', 0.75);
        $faces = [];

        foreach ($faceAnnotations as $face) {
            $confidence = $face->getDetectionConfidence();
            if ($confidence < $minConfidence) {
                continue;
            }

            $boundingBox = $this->extractBoundingBox($face->getBoundingPoly(), $imageWidth, $imageHeight);

            $faces[] = [
                'bounding_box' => $boundingBox,
                'confidence' => round($confidence, 4),
                'landmarks' => $this->extractLandmarks($face->getLandmarks(), $imageWidth, $imageHeight),
                'emotions' => [
                    'joy' => $this->likelihoodToFloat($face->getJoyLikelihood()),
                    'sorrow' => $this->likelihoodToFloat($face->getSorrowLikelihood()),
                    'anger' => $this->likelihoodToFloat($face->getAngerLikelihood()),
                    'surprise' => $this->likelihoodToFloat($face->getSurpriseLikelihood()),
                ],
                'angles' => [
                    'roll' => round($face->getRollAngle(), 2),
                    'pan' => round($face->getPanAngle(), 2),
                    'tilt' => round($face->getTiltAngle(), 2),
                ],
            ];
        }

        return $faces;
    }

    /**
     * Parse label annotations with confidence filtering.
     */
    private function parseLabelAnnotations(\Google\Cloud\Vision\V1\AnnotateImageResponse $response): array
    {
        $labelAnnotations = $response->getLabelAnnotations();
        if (! $labelAnnotations || $labelAnnotations->count() === 0) {
            return [];
        }

        $minConfidence = config('vision.thresholds.label_confidence', 0.70);
        $maxResults = config('vision.thresholds.label_max_results', 15);
        $labels = [];

        foreach ($labelAnnotations as $label) {
            if ($label->getScore() < $minConfidence) {
                continue;
            }

            $labels[] = [
                'name' => $label->getDescription(),
                'score' => round($label->getScore(), 4),
                'topicality' => round($label->getTopicality(), 4),
            ];

            if (count($labels) >= $maxResults) {
                break;
            }
        }

        return $labels;
    }

    /**
     * Convert Google BoundingPoly vertices to percentage-based bounding box.
     */
    private function extractBoundingBox($boundingPoly, int $imageWidth, int $imageHeight): array
    {
        if (! $boundingPoly || $boundingPoly->getVertices()->count() === 0) {
            return ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0];
        }

        $vertices = $boundingPoly->getVertices();
        $minX = PHP_INT_MAX;
        $minY = PHP_INT_MAX;
        $maxX = 0;
        $maxY = 0;

        foreach ($vertices as $vertex) {
            $x = $vertex->getX();
            $y = $vertex->getY();
            $minX = min($minX, $x);
            $minY = min($minY, $y);
            $maxX = max($maxX, $x);
            $maxY = max($maxY, $y);
        }

        return [
            'x' => round(($minX / $imageWidth) * 100, 2),
            'y' => round(($minY / $imageHeight) * 100, 2),
            'width' => round((($maxX - $minX) / $imageWidth) * 100, 2),
            'height' => round((($maxY - $minY) / $imageHeight) * 100, 2),
        ];
    }

    /**
     * Extract facial landmarks as percentage positions.
     */
    private function extractLandmarks($landmarks, int $imageWidth, int $imageHeight): ?array
    {
        if (! $landmarks || $landmarks->count() === 0) {
            return null;
        }

        $result = [];
        foreach ($landmarks as $landmark) {
            $position = $landmark->getPosition();
            if ($position) {
                $result[] = [
                    'type' => $landmark->getType(),
                    'x' => round(($position->getX() / $imageWidth) * 100, 2),
                    'y' => round(($position->getY() / $imageHeight) * 100, 2),
                ];
            }
        }

        return $result;
    }

    /**
     * Convert Google Likelihood enum to a float (0.0 to 1.0).
     */
    private function likelihoodToFloat(int $likelihood): float
    {
        return match ($likelihood) {
            Likelihood::VERY_UNLIKELY => 0.0,
            Likelihood::UNLIKELY => 0.2,
            Likelihood::POSSIBLE => 0.5,
            Likelihood::LIKELY => 0.8,
            Likelihood::VERY_LIKELY => 1.0,
            default => 0.0,
        };
    }

    private function getClient(): ImageAnnotatorClient
    {
        if ($this->client === null) {
            $config = [];

            $credentials = config('vision.google.credentials');
            if ($credentials) {
                $config['credentials'] = $credentials;
            }

            $projectId = config('vision.google.project_id');
            if ($projectId) {
                $config['projectId'] = $projectId;
            }

            $this->client = new ImageAnnotatorClient($config);
        }

        return $this->client;
    }

    public function __destruct()
    {
        if ($this->client) {
            $this->client->close();
        }
    }
}
