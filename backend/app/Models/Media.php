<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Media extends Model
{
    use HasFactory, HasUuids, Searchable, SoftDeletes;

    /**
     * Colonnes indexées pour la recherche unifiée.
     * Uniquement de vraies colonnes : compatible avec les drivers
     * meilisearch (prod) et database (tests).
     */
    public function toSearchableArray(): array
    {
        return [
            'original_name' => $this->original_name,
            'title'         => $this->title,
            'description'   => $this->description,
        ];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'source_media_id',
        'type',
        'original_name',
        'title',
        'description',
        'file_path',
        'content_hash',
        'perceptual_hash',
        'mime_type',
        'size',
        'width',
        'height',
        'duration',
        'clip_start',
        'clip_end',
        'is_source',
        'video_codec',
        'audio_codec',
        'fps',
        'bitrate',
        'uploaded_at',
        'taken_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'uploaded_at' => 'datetime',
            'taken_at' => 'datetime',
            // Le driver pgsql renvoie les colonnes float/numeric en string
            'fps' => 'float',
            'clip_start' => 'float',
            'clip_end' => 'float',
            'is_source' => 'boolean',
        ];
    }

    /**
     * Get the user that uploaded the media.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Vidéo source dont ce média est un clip (null si média normal).
     */
    public function sourceMedia()
    {
        return $this->belongsTo(Media::class, 'source_media_id');
    }

    /**
     * Clips découpés à partir de ce média (si c'est une vidéo source).
     */
    public function clips()
    {
        return $this->hasMany(Media::class, 'source_media_id');
    }

    /**
     * Get the media's metadata.
     */
    public function metadata()
    {
        return $this->hasOne(MediaMetadata::class);
    }

    /**
     * Get the media's conversions (thumbnails, etc).
     */
    public function conversions()
    {
        return $this->hasMany(MediaConversion::class);
    }

    /**
     * Get all tags for this media.
     */
    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /**
     * Get the albums this media belongs to.
     */
    public function albums()
    {
        return $this->belongsToMany(Album::class, 'album_media')
            ->withPivot('order')
            ->withTimestamps();
    }

    /**
     * Get the people tagged in this media.
     */
    public function people()
    {
        return $this->belongsToMany(Person::class, 'media_person')
            ->withPivot('face_coordinates')
            ->withTimestamps();
    }

    /**
     * Get the detected faces in this media.
     */
    public function detectedFaces()
    {
        return $this->hasMany(DetectedFace::class);
    }

    /**
     * Médias accessibles par $user : ceux qu'il possède, ou présents dans un
     * album accessible (public / accès accordé / tagué). La galerie reste
     * privée : un média hors album partagé n'est visible que du propriétaire.
     */
    public function scopeAccessibleBy($query, ?User $user)
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhereHas('albums', fn ($a) => $a->accessibleBy($user));
        });
    }

    /**
     * Get a human-readable resolution label based on video height.
     */
    public function getResolutionLabelAttribute(): ?string
    {
        if (! $this->height) {
            return null;
        }

        return match (true) {
            $this->height >= 2160 => '4K',
            $this->height >= 1080 => '1080p',
            $this->height >= 720  => '720p',
            default               => "{$this->height}p",
        };
    }
}
