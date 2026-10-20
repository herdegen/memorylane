<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Télévision reliée à un compte. Tant que user_id est vide, la ligne n'est
 * qu'un code d'appairage affiché sur l'écran (valable PAIRING_MINUTES).
 */
class TvDevice extends Model
{
    use HasUuids;

    /** Durée de validité d'un code affiché sur la TV. */
    public const PAIRING_MINUTES = 10;

    /** Cookie (chiffré) qui garde la TV connectée d'une session à l'autre. */
    public const COOKIE = 'ml_tv';

    /** Durée du cookie de la TV (minutes) : 5 ans, révocable depuis le téléphone. */
    public const COOKIE_MINUTES = 60 * 24 * 365 * 5;

    protected $fillable = ['user_id', 'household_id', 'name', 'pairing_code', 'pairing_expires_at', 'token_hash', 'confirmed_at', 'last_seen_at'];

    protected $hidden = ['pairing_code', 'token_hash'];

    protected $casts = [
        'pairing_expires_at' => 'datetime',
        'confirmed_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function household()
    {
        return $this->belongsTo(Household::class);
    }

    /** Codes en attente encore valables (pas encore validés depuis un téléphone). */
    public function scopePending($query)
    {
        return $query->whereNull('user_id')->whereNotNull('pairing_code')->where('pairing_expires_at', '>', now());
    }

    /** Télévisions effectivement reliées (jeton remis à l'appareil). */
    public function scopeLinked($query)
    {
        return $query->whereNotNull('user_id')->whereNotNull('token_hash');
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** Nouveau code à 6 chiffres, distinct des codes en attente. */
    public static function freshCode(): string
    {
        do {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (static::where('pairing_code', $code)->where('pairing_expires_at', '>', now())->exists());

        return $code;
    }

    /** Codes expirés (affichés mais jamais validés, ou validés mais jamais récupérés). */
    public static function purgeExpired(): void
    {
        static::whereNull('token_hash')->where('pairing_expires_at', '<', now())->delete();
    }

    public static function newToken(): string
    {
        return Str::random(64);
    }
}
