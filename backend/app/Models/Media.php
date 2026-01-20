<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Media extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'original_name',
        'file_path',
        'mime_type',
        'size',
        'width',
        'height',
        'duration',
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
}
