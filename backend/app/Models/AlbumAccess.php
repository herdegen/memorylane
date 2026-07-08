<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Accès accordé à un album non-public (partage à un compte choisi).
 * granted_by trace qui a accordé l'accès (pour la révocation / délégation).
 */
class AlbumAccess extends Model
{
    use HasUuids;

    protected $table = 'album_access';

    protected $fillable = [
        'album_id',
        'user_id',
        'granted_by',
    ];

    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function granter()
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
