<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Réponse d'un compte à une étape du récit de vie d'une personne. */
class LifeStoryAnswer extends Model
{
    use HasUuids;

    protected $fillable = [
        'person_id',
        'user_id',
        'chapter',
        'status',
        'body',
        'event_ids',
        'audio_path',
        'audio_duration',
        'transcript',
        'analyzed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_ids' => 'array',
            'analyzed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn (LifeStoryAnswer $answer) => $answer->audio_path && Storage::delete($answer->audio_path));
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /** Le récit (texte, voix) : son auteur, la personne elle-même, les admins. */
    public function isVisibleTo(?User $user): bool
    {
        return $user !== null && ($user->isAdmin() || $this->user_id === $user->id
            || ($user->person_id !== null && $user->person_id === $this->person_id));
    }
}
