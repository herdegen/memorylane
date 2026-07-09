<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class LifeEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'person_id',
        'user_id',
        'type',
        'title',
        'description',
        'place',
        'event_date',
        'end_date',
        'media_id',
    ];

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function media()
    {
        return $this->belongsTo(Media::class);
    }
}
