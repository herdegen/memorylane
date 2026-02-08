<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Person extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'people';

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'birth_date',
        'death_date',
        'avatar_media_id',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'death_date' => 'date',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($person) {
            if (empty($person->slug)) {
                $person->slug = Str::slug($person->name);
                // Make unique if needed
                $count = 1;
                $originalSlug = $person->slug;
                while (static::where('slug', $person->slug)->exists()) {
                    $person->slug = $originalSlug . '-' . $count++;
                }
            }
        });

        static::updating(function ($person) {
            if ($person->isDirty('name')) {
                $person->slug = Str::slug($person->name);
                $count = 1;
                $originalSlug = $person->slug;
                while (static::where('slug', $person->slug)->where('id', '!=', $person->id)->exists()) {
                    $person->slug = $originalSlug . '-' . $count++;
                }
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function avatar()
    {
        return $this->belongsTo(Media::class, 'avatar_media_id');
    }

    public function media()
    {
        return $this->belongsToMany(Media::class, 'media_person')
            ->withPivot('face_coordinates')
            ->withTimestamps();
    }

    public function detectedFaces()
    {
        return $this->hasMany(DetectedFace::class);
    }
}
