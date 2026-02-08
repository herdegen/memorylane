<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MediaMetadata extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'media_id',
        'exif_data',
        'camera_make',
        'camera_model',
        'iso',
        'aperture',
        'shutter_speed',
        'focal_length',
        'latitude',
        'longitude',
        'altitude',
        'vision_labels',
        'vision_status',
        'vision_provider',
        'vision_processed_at',
        'vision_error',
        'vision_faces_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected function casts(): array
    {
        return [
            'exif_data' => 'array',
            'iso' => 'integer',
            'aperture' => 'decimal:2',
            'focal_length' => 'integer',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'altitude' => 'decimal:2',
            'vision_labels' => 'array',
            'vision_processed_at' => 'datetime',
            'vision_faces_count' => 'integer',
        ];
    }

    /**
     * Get the media that this metadata belongs to.
     */
    public function media()
    {
        return $this->belongsTo(Media::class);
    }
}
