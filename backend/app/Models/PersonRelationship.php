<?php

namespace App\Models;

use App\Observers\PersonRelationshipObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(PersonRelationshipObserver::class)]
class PersonRelationship extends Model
{
    use HasUuids;

    protected $fillable = [
        'person1_id',
        'person2_id',
        'type',
        'start_date',
        'end_date',
        'start_place',
        // Photo de l'union (carte « Mariage » de la frise), au choix.
        'media_id',
    ];

    protected static function booted(): void
    {
        // Lieu de l'union saisi ou modifié : géocodé en tâche de fond.
        static::saved(fn (self $union) => \App\Jobs\GeocodePendingPlaces::enqueueChanged($union, ['start_place']));
    }

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function media()
    {
        return $this->belongsTo(Media::class);
    }

    public function person1()
    {
        return $this->belongsTo(Person::class, 'person1_id');
    }

    public function person2()
    {
        return $this->belongsTo(Person::class, 'person2_id');
    }
}
