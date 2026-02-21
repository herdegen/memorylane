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
        'maiden_name',
        'birth_date',
        'birth_place',
        'death_date',
        'death_place',
        'avatar_media_id',
        'notes',
        'father_id',
        'mother_id',
        'gender',
        'gedcom_id',
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

    public function father()
    {
        return $this->belongsTo(Person::class, 'father_id');
    }

    public function mother()
    {
        return $this->belongsTo(Person::class, 'mother_id');
    }

    public function childrenAsFather()
    {
        return $this->hasMany(Person::class, 'father_id');
    }

    public function childrenAsMother()
    {
        return $this->hasMany(Person::class, 'mother_id');
    }

    public function getChildrenAttribute()
    {
        return Person::where('father_id', $this->id)
            ->orWhere('mother_id', $this->id)
            ->get();
    }

    public function spousesAsFirst()
    {
        return $this->belongsToMany(Person::class, 'person_relationships', 'person1_id', 'person2_id')
            ->withPivot('type', 'start_date', 'end_date', 'start_place')
            ->withTimestamps();
    }

    public function spousesAsSecond()
    {
        return $this->belongsToMany(Person::class, 'person_relationships', 'person2_id', 'person1_id')
            ->withPivot('type', 'start_date', 'end_date', 'start_place')
            ->withTimestamps();
    }

    public function getSpousesAttribute()
    {
        return $this->spousesAsFirst->merge($this->spousesAsSecond);
    }

    public function getParentsAttribute()
    {
        return collect([$this->father, $this->mother])->filter()->values();
    }
}
