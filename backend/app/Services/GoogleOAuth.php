<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Connexion OAuth Google partagée par les imports Google Photos et Google
 * Drive : un seul client OAuth (GOOGLE_PHOTOS_CLIENT_*) et une seule URI de
 * retour (/auth/google/callback) déclarée dans la console Google Cloud.
 */
class GoogleOAuth
{
    public const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * URL de consentement Google. `$offline` demande en plus un refresh
     * token (consentement redemandé, sinon Google ne le renvoie qu'une fois).
     */
    public function authorizeUrl(string $scope, string $state, bool $offline = false): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query(array_filter([
            'client_id' => config('services.google_photos.client_id'),
            'redirect_uri' => config('services.google_photos.redirect'),
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'access_type' => $offline ? 'offline' : null,
            'prompt' => $offline ? 'select_account consent' : 'select_account',
        ]));
    }

    /**
     * Échange le code du callback contre des jetons, ou null si Google refuse.
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}|null
     */
    public function exchangeCode(string $code): ?array
    {
        return $this->requestToken([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google_photos.redirect'),
        ]);
    }

    /**
     * Nouveau jeton d'accès à partir du refresh token (le refresh token est
     * conservé : Google n'en renvoie pas de nouveau).
     *
     * @return array{access_token: string, refresh_token: ?string, expires_at: int}|null
     */
    public function refresh(string $refreshToken): ?array
    {
        $token = $this->requestToken([
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if ($token) {
            $token['refresh_token'] ??= $refreshToken;
        }

        return $token;
    }

    protected function requestToken(array $params): ?array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, $params + [
            'client_id' => config('services.google_photos.client_id'),
            'client_secret' => config('services.google_photos.client_secret'),
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            Log::warning('GoogleOAuth: token request failed', [
                'grant_type' => $params['grant_type'],
                'status' => $response->status(),
                'error' => $response->json('error'),
            ]);

            return null;
        }

        return [
            'access_token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => now()->addSeconds((int) $response->json('expires_in', 3600))->timestamp,
        ];
    }
}
