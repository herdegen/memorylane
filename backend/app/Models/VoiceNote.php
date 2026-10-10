<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/**
 * Mémo vocal attaché à une photo (« Raconter cette photo »).
 */
class VoiceNote extends Model
{
    use HasUuids;

    /** Durée maximale d'un enregistrement (secondes). */
    public const MAX_SECONDS = 1200;

    protected $fillable = [
        'media_id',
        'user_id',
        'person_id',
        'file_path',
        'mime_type',
        'size',
        'duration_seconds',
    ];

    protected $casts = [
        'size' => 'integer',
        'duration_seconds' => 'integer',
    ];

    protected static function booted(): void
    {
        // Le fichier audio part avec la ligne (y compris en cascade via le média).
        static::deleted(fn (VoiceNote $note) => Storage::delete($note->file_path));
    }

    public function media()
    {
        return $this->belongsTo(Media::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /** Peut supprimer : l'auteur, le propriétaire de la photo, un admin. */
    public function deletableBy(User $user): bool
    {
        return $user->isAdmin()
            || $this->user_id === $user->id
            || $this->media?->user_id === $user->id;
    }
}
