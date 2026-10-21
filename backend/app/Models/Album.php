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
        'share_with_tagged',
    ];

    /**
     * Le jeton du lien anonyme n'est jamais envoyé au navigateur : seul le
     * propriétaire voit l'URL (AlbumController::show) ; les autres savent
     * seulement qu'un lien existe.
     */
    protected $hidden = ['share_token'];

    protected $appends = ['has_share_link'];

    public function getHasShareLinkAttribute(): bool
    {
        return ! empty($this->attributes['share_token'] ?? null);
    }

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'is_smart' => 'boolean',
            'smart_rules' => 'array',
            'share_with_tagged' => 'boolean',
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
        // Tri : d'abord les médias « à la une » (pin_order, ordre choisi),
        // puis chronologique sur la date de prise de vue (à défaut la date
        // d'upload). Le champ pivot `order` (réordonnancement manuel) est
        // conservé mais n'est plus le tri par défaut.
        return $this->belongsToMany(Media::class, 'album_media')
            ->withPivot('order', 'pin_order')
            ->withTimestamps()
            ->orderByRaw('album_media.pin_order IS NULL, album_media.pin_order, COALESCE(media.taken_at, media.uploaded_at) ASC');
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
     * « Qui était du voyage ? » : les personnes présentes sur l'album.
     */
    public function participants()
    {
        return $this->belongsToMany(Person::class, 'album_participants')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    /**
     * Cercles avec lesquels l'album est partagé.
     */
    public function households()
    {
        return $this->belongsToMany(Household::class, 'album_household')
            ->withPivot('granted_by')
            ->withTimestamps();
    }

    /**
     * Un album est accessible en lecture par :
     *  - un lien anonyme valide (token) ;
     *  - son propriétaire ;
     *  - un compte à qui l'accès a été accordé (album_access) ;
     *  - les membres d'un cercle avec lequel il est partagé (album_household).
     * Être identifié sur une photo ne donne accès qu'à cette photo
     * (Media::scopeAccessibleBy), pas à l'album.
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

        // Invité temporaire : seulement les albums où figure sa fiche.
        if ($user->isGuest()) {
            return $user->guest_person_id
                && $this->media()->whereHas('people', fn ($q) => $q->where('people.id', $user->guest_person_id))->exists();
        }

        if ($this->user_id === $user->id) {
            return true;
        }

        if ($this->accesses()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if ($this->households()->whereIn('households.id', $user->householdIds())->exists()) {
            return true;
        }

        // Être identifié sur une photo n'ouvre l'album entier QUE si le
        // propriétaire l'a choisi (share_with_tagged) ; sinon on voit
        // seulement les photos où l'on apparaît (Media::scopeAccessibleBy).
        return $this->share_with_tagged
            && $user->person_id
            && static::whereKey($this->id)->where(fn ($q) => static::whereTaggedPerson($q, $user->person_id))->exists();
    }

    /**
     * Album partagé avec ses personnes identifiées, où $personId figure sur
     * une photo PAR UN HUMAIN : nommée à la main (pas de visage d'elle sur la
     * photo) ou visage validé — jamais sur la seule foi d'une association
     * automatique (une erreur de reconnaissance n'ouvre pas un album).
     */
    public static function whereTaggedPerson($query, string $personId)
    {
        return $query->where('albums.share_with_tagged', true)
            ->whereExists(fn ($q) => $q->selectRaw('1')
                ->from('album_media as am')
                ->join('media_person as mp', 'mp.media_id', '=', 'am.media_id')
                ->join('media as m', 'm.id', '=', 'am.media_id')
                ->whereColumn('am.album_id', 'albums.id')
                ->where('mp.person_id', $personId)
                ->whereNull('m.deleted_at')
                ->where(fn ($w) => $w
                    ->whereNotExists(fn ($d) => $d->selectRaw('1')->from('detected_faces as df')
                        ->whereColumn('df.media_id', 'mp.media_id')->whereColumn('df.person_id', 'mp.person_id'))
                    ->orWhereExists(fn ($d) => $d->selectRaw('1')->from('detected_faces as df')
                        ->whereColumn('df.media_id', 'mp.media_id')->whereColumn('df.person_id', 'mp.person_id')
                        ->whereNotNull('df.verified_at'))));
    }

    /**
     * Albums accessibles par $user (owner | accès accordé | cercle).
     * Il n'y a plus d'album « public pour tous » : on partage avec un cercle.
     * Sert à « Partagés avec moi » et aux agrégations (carte, médias accessibles).
     */
    /**
     * Albums du CONTEXTE courant, même règle que la liste des albums (cf.
     * AlbumController::albumIdsInContext) : cercle d'amis → partagés avec lui
     * ou montrant un ami du cercle ; famille → partagés avec un cercle
     * familial, ou privés (un album partagé seulement entre amis est
     * « ailleurs »). Ne vérifie pas l'accès : à combiner avec accessibleBy.
     */
    public function scopeInContext($query, User $user)
    {
        $circle = $user->currentCircle();

        if ($circle?->isFriends()) {
            $friendIds = $circle->people()
                ->when($user->person_id, fn ($q) => $q->where('people.id', '!=', $user->person_id))
                ->pluck('people.id');

            return $query->where(fn ($w) => $w
                ->whereHas('households', fn ($h) => $h->whereKey($circle->id))
                ->orWhereHas('media.people', fn ($p) => $p->whereIn('people.id', $friendIds)));
        }

        return $query->where(fn ($w) => $w
            ->whereDoesntHave('households')
            ->orWhereHas('households', fn ($h) => $h->where('kind', Household::KIND_FAMILY)));
    }

    public function scopeAccessibleBy($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        // Invité temporaire : seulement les albums où figure sa fiche.
        if ($user->isGuest()) {
            return $query->whereHas('media.people', fn ($p) => $p->where('people.id', $user->guest_person_id ?? ''));
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('accesses', fn ($a) => $a->where('user_id', $user->id))
                // Branche cercle : album partagé avec un cercle du viewer.
                ->orWhereHas('households', fn ($h) => $h->whereIn('households.id', $user->householdIds()));

            // Album partagé avec les personnes identifiées dedans.
            if ($user->person_id) {
                $q->orWhere(fn ($t) => static::whereTaggedPerson($t, $user->person_id));
            }
        });
    }
}
