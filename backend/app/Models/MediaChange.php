<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Une correction apportée à une photo (date, titre, lieu, visages), par son
 * auteur ou par un autre membre qui la voit (cf. MediaHistory).
 */
class MediaChange extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Corrections qu'on sait défaire. */
    public const REVERTIBLE = ['updated', 'geolocated', 'geolocation_removed', 'face_identified', 'face_reset', 'face_dismissed', 'face_added'];

    protected $fillable = ['media_id', 'user_id', 'action', 'diff'];

    protected function casts(): array
    {
        return [
            'diff' => 'array',
            'reverted_at' => 'datetime',
        ];
    }

    public function media()
    {
        return $this->belongsTo(Media::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function revertedBy()
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    public function isRevertible(): bool
    {
        return $this->reverted_at === null && in_array($this->action, self::REVERTIBLE, true);
    }
}
