<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Histoire de famille : un récit en chapitres qui concerne une ou plusieurs
 * personnes. Elle suit sa personne principale : qui voit cette fiche peut la
 * lire, une fois publiée (l'auteur et les admins la voient dès le brouillon).
 */
class Story extends Model
{
    use HasUuids, SoftDeletes;

    public const KINDS = ['documented', 'memory', 'legend'];

    protected $fillable = [
        'user_id', 'title', 'summary', 'kind', 'credits', 'album_id', 'sensitive', 'disagreements', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'sensitive' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function chapters()
    {
        return $this->hasMany(StoryChapter::class)->orderBy('position');
    }

    public function people()
    {
        return $this->belongsToMany(Person::class, 'story_person')
            ->withPivot(['is_main', 'role', 'position'])
            ->orderBy('story_person.position');
    }

    public function questions()
    {
        return $this->hasMany(StoryQuestion::class)->orderBy('position');
    }

    public function notes()
    {
        return $this->hasMany(StoryNote::class)->orderBy('created_at');
    }

    /** Histoires lisibles : publiées (ou les siennes), dont la personne principale est visible. */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where(fn ($q) => $q->whereNotNull('published_at')->orWhere('user_id', $user->id))
            ->whereHas('people', fn ($p) => $p->where('story_person.is_main', true)->visibleTo($user));
    }

    public function isVisibleTo(?User $user): bool
    {
        return $user !== null && static::visibleTo($user)->whereKey($this->id)->exists();
    }

    /** L'auteur et les admins modifient l'histoire. */
    public function canEdit(?User $user): bool
    {
        return $user !== null && ($user->isAdmin() || $this->user_id === $user->id);
    }

    public function mainPerson(): ?Person
    {
        return $this->people->first(fn (Person $p) => $p->pivot->is_main) ?? $this->people->first();
    }

    /** Identifiants des médias cités dans les chapitres, dans l'ordre. */
    public function mediaIds(): array
    {
        return $this->chapters
            ->flatMap(fn (StoryChapter $c) => collect($c->blocks)->where('type', 'media')->pluck('media_id'))
            ->filter()->unique()->values()->all();
    }
}
