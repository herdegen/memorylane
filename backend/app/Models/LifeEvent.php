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
        'latitude',
        'longitude',
        'event_date',
        'end_date',
        'date_precision',
        'media_id',
        'album_id',
    ];

    protected static function booted(): void
    {
        // Lieu de vie saisi sans coordonnées : géocodé en tâche de fond.
        static::saved(function (self $event) {
            $touched = $event->wasRecentlyCreated || $event->wasChanged(['place', 'type', 'latitude', 'longitude']);
            if ($touched && $event->type === 'residence' && filled($event->place)
                && ($event->latitude === null || $event->longitude === null)) {
                \App\Jobs\GeocodePendingPlaces::enqueue([$event->place]);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'end_date' => 'date',
            'latitude' => 'float',
            'longitude' => 'float',
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

    /**
     * Galerie du moment : plusieurs photos, dans l'ordre choisi (la rafale du
     * récit de vie les déroule). Indépendante de l'illustration `media_id`.
     */
    public function photos()
    {
        return $this->belongsToMany(Media::class, 'life_event_media')
            ->withPivot('order')
            ->withTimestamps()
            ->orderBy('life_event_media.order');
    }

    /**
     * Album (galerie) illustrant le moment — ex. l'album du baptême.
     */
    public function album()
    {
        return $this->belongsTo(Album::class);
    }
}
