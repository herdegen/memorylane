<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Invitation à créer son accès, reliée à une fiche de l'arbre. Envoyée par
 * l'admin depuis le back-office ou par un membre depuis une fiche.
 */
class Invitation extends Model
{
    use HasUuids;

    /** Durée de validité du lien. */
    public const VALID_DAYS = 30;

    protected $fillable = [
        'email',
        'person_id',
        'household_id',
        'invited_by',
        'user_id',
        'formal',
        'message',
    ];

    protected $hidden = [
        'token_hash',
        'token',
    ];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'formal' => 'boolean',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function household()
    {
        return $this->belongsTo(Household::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function findByToken(string $token): ?self
    {
        return static::where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * pending | accepted | revoked | expired
     */
    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => 'accepted',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at !== null && $this->expires_at->isPast() => 'expired',
            default => 'pending',
        };
    }

    public function isUsable(): bool
    {
        return $this->status() === 'pending' && $this->token_hash !== null;
    }

    /**
     * Invitations encore ouvertes (ni acceptées, ni révoquées, ni échues).
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function url(): ?string
    {
        return $this->token ? route('invitations.show', $this->token) : null;
    }
}
