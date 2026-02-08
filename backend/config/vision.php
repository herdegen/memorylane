<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vision AI Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Vision AI service used for face detection and
    | label detection on uploaded photos. Supports multiple providers
    | via the VisionServiceInterface abstraction.
    |
    */

    'enabled' => env('VISION_ENABLED', false),

    'provider' => env('VISION_PROVIDER', 'google'),

    'google' => [
        'project_id' => env('GOOGLE_CLOUD_PROJECT'),
        'credentials' => env('GOOGLE_APPLICATION_CREDENTIALS'),
    ],

    'thresholds' => [
        'face_confidence' => (float) env('VISION_FACE_CONFIDENCE', 0.75),
        'label_confidence' => (float) env('VISION_LABEL_CONFIDENCE', 0.70),
        'label_max_results' => (int) env('VISION_LABEL_MAX', 15),
    ],

    'auto_tag' => env('VISION_AUTO_TAG', true),

];
