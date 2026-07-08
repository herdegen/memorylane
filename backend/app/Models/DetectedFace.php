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
    ];

    protected function casts(): array
    {
        return [
            'bounding_box' => 'array',
            'landmarks' => 'array',
            'embedding' => 'array',
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

    public function media()
    {
        return $this->belongsTo(Media::class);
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }
}
