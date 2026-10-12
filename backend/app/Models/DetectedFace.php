<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DetectedFace extends Model
{
    use HasFactory, HasUuids;

    /**
     * Le descripteur facial (128 floats) est volumineux et inutile côté client :
     * on ne l'expose jamais dans le JSON des visages.
     */
    protected $hidden = [
        'embedding',
    ];

    protected $fillable = [
        'media_id',
        'person_id',
        'bounding_box',
        'confidence',
        'landmarks',
        'embedding',
        'joy_likelihood',
        'sorrow_likelihood',
        'anger_likelihood',
        'surprise_likelihood',
        'roll_angle',
        'pan_angle',
        'tilt_angle',
        'provider',
        'status',
        'embedding_v2',
        'age_estimate',
        'engine',
        'verified_at',
    ];

    /**
     * Toute identification qui change invalide les références de
     * reconnaissance en cache (FaceMatcher::references).
     */
    protected static function booted(): void
    {
        static::saved(function (DetectedFace $face) {
            if ($face->person_id && ($face->wasRecentlyCreated || $face->wasChanged(['person_id', 'embedding', 'embedding_v2', 'verified_at']))
                || $face->wasChanged('person_id')) {
                \App\Services\Vision\FaceMatcher::bumpReferences();
            }
        });
        static::deleted(function (DetectedFace $face) {
            if ($face->person_id) {
                \App\Services\Vision\FaceMatcher::bumpReferences();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'bounding_box' => 'array',
            'landmarks' => 'array',
            'embedding' => 'array',
            'embedding_v2' => 'array',
            'age_estimate' => 'integer',
            'verified_at' => 'datetime',
            'confidence' => 'float',
            'joy_likelihood' => 'float',
            'sorrow_likelihood' => 'float',
            'anger_likelihood' => 'float',
            'surprise_likelihood' => 'float',
            'roll_angle' => 'float',
            'pan_angle' => 'float',
            'tilt_angle' => 'float',
        ];
    }

    /**
     * Visages encore « à identifier » (ni identifiés, ni écartés comme
     * inconnus ou faux cadres).
     */
    public function scopeUnidentified($query)
    {
        return $query->where('detected_faces.status', 'unmatched')->whereNull('detected_faces.person_id');
    }

    public function media()
    {
        return $this->belongsTo(Media::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * Personne associée d'un autre cercle (fiche invisible du viewer) : on
     * garde son NOM — qui voit la photo voit qui est dessus — mais rien
     * d'autre (Person::restrictedStub) ; `person_restricted` verrouille la
     * correction côté front. À appeler sur des visages déjà `person`-chargés.
     *
     * @param  iterable<DetectedFace>  $faces
     */
    public static function maskPeopleFor(iterable $faces, ?User $viewer): void
    {
        $ids = collect($faces)->pluck('person_id')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return;
        }

        $visible = Person::visibleTo($viewer)->whereIn('people.id', $ids)->pluck('people.id')->flip();

        foreach ($faces as $face) {
            if ($face->person_id && ! isset($visible[$face->person_id])) {
                $face->setRelation('person', $face->person ? Person::restrictedStub($face->person) : null);
                $face->setAttribute('person_restricted', true);
            }
        }
    }

    /** La personne associée (s'il y en a une) est-elle visible du viewer ? */
    public function personVisibleTo(?User $viewer): bool
    {
        return ! $this->person_id || Person::visibleTo($viewer)->whereKey($this->person_id)->exists();
    }
}
