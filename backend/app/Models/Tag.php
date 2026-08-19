<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Scout\Searchable;

class Tag extends Model
{
    use HasFactory, Searchable;

    public function toSearchableArray(): array
    {
        return [
            'name' => $this->name,
        ];
    }

    protected $fillable = [
        'name',
        'slug',
        'color',
        'type',
    ];

    /**
     * Get all media tagged with this tag.
     */
    public function media()
    {
        return $this->morphedByMany(Media::class, 'taggable');
    }

    /**
     * Boot the model and set slug automatically.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tag) {
            if (empty($tag->slug)) {
                $tag->slug = Str::slug($tag->name);
            }
        });

        static::updating(function ($tag) {
            // Le slug suit toujours le nom : l'auto-tag Vision déduplique par
            // slug, un slug figé après renommage créerait des doublons.
            if ($tag->isDirty('name') && ! $tag->isDirty('slug')) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }
}
