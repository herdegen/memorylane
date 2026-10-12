<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Supervision externe facultative (ex. healthchecks.io) : pingée à chaque
    // vérification horaire (« /fail » en cas de problème). Si les pings
    // cessent (serveur ou planificateur arrêté), le service prévient.
    'healthchecks' => [
        'ping_url' => env('HEALTHCHECK_PING_URL'),
    ],

    'google_photos' => [
        'client_id' => env('GOOGLE_PHOTOS_CLIENT_ID'),
        'client_secret' => env('GOOGLE_PHOTOS_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_PHOTOS_REDIRECT_URI', env('APP_URL') . '/auth/google/callback'),
    ],

    // Import Google Drive : même client OAuth que Google Photos, plus une clé
    // d'API (navigateur) pour le sélecteur Google Picker. app_id = numéro du
    // projet Google Cloud (déduit de l'ID client s'il est vide).
    'google_drive' => [
        'api_key' => env('GOOGLE_DRIVE_API_KEY'),
        'app_id' => env('GOOGLE_DRIVE_APP_ID'),
    ],

];
