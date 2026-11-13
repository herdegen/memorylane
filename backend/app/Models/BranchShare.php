<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Partage vivant avec une branche : le média ou l'album est visible de tous
 * les descendants de `person_id` (elle comprise) qui ont un compte, et de
 * leurs conjoints actuels si `include_spouses`. Voir BranchAccess.
 */
class BranchShare extends Model
{
    use HasUuids;

    public const MEDIA = 'media';

    public const ALBUM = 'album';

    protected $fillable = ['shareable_type', 'shareable_id', 'person_id', 'include_spouses', 'created_by'];

    protected function casts(): array
    {
        return ['include_spouses' => 'boolean'];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
