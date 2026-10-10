<?php

namespace App\Models;

use NotificationChannels\WebPush\PushSubscription as BasePushSubscription;

/**
 * Abonnement Web Push d'un appareil. Étend le modèle du package pour porter
 * le user agent et en déduire un libellé lisible dans le profil.
 *
 * @property string|null $user_agent
 */
class PushSubscription extends BasePushSubscription
{
    protected $fillable = [
        'endpoint',
        'public_key',
        'auth_token',
        'content_encoding',
        'user_agent',
    ];

    /**
     * Libellé « Appareil · Navigateur » déduit du user agent. Volontairement
     * grossier : il sert à reconnaître son téléphone dans une liste de 2-3
     * appareils, pas à faire de la statistique.
     */
    public function getLabelAttribute(): string
    {
        $ua = (string) $this->user_agent;
        if ($ua === '') {
            return 'Appareil inconnu';
        }

        $device = match (true) {
            str_contains($ua, 'iPhone') => 'iPhone',
            str_contains($ua, 'iPad') => 'iPad',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Macintosh') => 'Mac',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Appareil',
        };

        // Ordre important : Edge et Chrome iOS se présentent aussi comme Chrome/Safari.
        $browser = match (true) {
            str_contains($ua, 'Edg') => 'Edge',
            str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'FxiOS'), str_contains($ua, 'Firefox') => 'Firefox',
            str_contains($ua, 'Chrome') => 'Chrome',
            str_contains($ua, 'Safari') => 'Safari',
            default => null,
        };

        return $browser ? "{$device} · {$browser}" : $device;
    }

    /**
     * Représentation envoyée au front (profil) : jamais les clés de chiffrement.
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'endpoint' => $this->endpoint,
            'label' => $this->label,
            'created_at' => $this->created_at,
        ];
    }
}
