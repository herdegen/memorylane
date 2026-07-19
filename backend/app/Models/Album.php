<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Album extends Model
{
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
        ];
    }

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'cover_media_id',
        'is_public',
        'is_smart',
        'smart_rules',
        'share_token',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_smart' => 'boolean',
            'smart_rules' => 'array',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($album) {
            if (empty($album->slug)) {
                $album->slug = Str::slug($album->name);
            }
        });

        static::updating(function ($album) {
            if ($album->isDirty('name')) {
                $album->slug = Str::slug($album->name);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function coverMedia()
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function media()
    {
        // Tri par défaut : chronologique sur la date de prise de vue (à défaut
        // la date d'upload). Le champ pivot `order` (réordonnancement manuel)
        // est conservé mais n'est plus le tri par défaut.
        return $this->belongsToMany(Media::class, 'album_media')
            ->withPivot('order')
            ->withTimestamps()
            ->orderByRaw('COALESCE(media.taken_at, media.uploaded_at) ASC');
    }

    /**
     * Accès accordés (partage à des comptes choisis).
     */
    public function accesses()
    {
        return $this->hasMany(AlbumAccess::class);
    }

    public function generateShareToken(): string
    {
        $this->share_token = Str::random(64);
        $this->save();
        return $this->share_token;
    }

    public function revokeShareToken(): void
    {
        $this->share_token = null;
        $this->save();
    }

    public function getShareUrl(): ?string
    {
        if (!$this->share_token) {
            return null;
        }
        return url("/albums/shared/{$this->share_token}");
    }

    /**
     * Un album est accessible en lecture par :
     *  - un lien anonyme valide (token) ;
     *  - son propriétaire ;
     *  - tout utilisateur connecté si l'album est public ;
     *  - un compte à qui l'accès a été accordé (album_access) ;
     *  - une personne taguée (avec compte lié) dans un média de l'album,
     *    quelle que soit la visibilité.
     */
    public function isAccessibleBy(?User $user, ?string $token = null): bool
    {
        // Lien de partage anonyme (chemin séparé, non authentifié)
        if ($token && $this->share_token === $token) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if ($this->user_id === $user->id) {
            return true;
        }

        if ($this->is_public) {
            return true;
        }

        if ($this->accesses()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if ($user->person_id
            && $this->media()->whereHas('people', fn ($q) => $q->where('people.id', $user->person_id))->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Albums accessibles par $user (owner | public | accès accordé | tagué).
     * Sert à « Partagés avec moi » et aux agrégations (carte, médias accessibles).
     */
    public function scopeAccessibleBy($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere('is_public', true)
                ->orWhereHas('accesses', fn ($a) => $a->where('user_id', $user->id));

            if ($user->person_id) {
                $q->orWhereHas('media.people', fn ($p) => $p->where('people.id', $user->person_id));
            }
        });
    }
}
