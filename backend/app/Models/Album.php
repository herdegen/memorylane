<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Album extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'cover_media_id',
        'is_public',
        'share_token',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
        ];
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($album) {
            if (empty($album->slug)) {
                $album->slug = Str::slug($album->name);
            }
        });

        static::updating(function ($album) {
            if ($album->isDirty('name')) {
                $album->slug = Str::slug($album->name);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function coverMedia()
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function media()
    {
        return $this->belongsToMany(Media::class, 'album_media')
            ->withPivot('order')
            ->withTimestamps()
            ->orderBy('album_media.order');
    }

    public function generateShareToken(): string
    {
        $this->share_token = Str::random(64);
        $this->save();
        return $this->share_token;
    }

    public function revokeShareToken(): void
    {
        $this->share_token = null;
        $this->save();
    }

    public function getShareUrl(): ?string
    {
        if (!$this->share_token) {
            return null;
        }
        return url("/albums/shared/{$this->share_token}");
    }

    public function isAccessibleBy(?User $user, ?string $token = null): bool
    {
        if ($user && $this->user_id === $user->id) {
            return true;
        }

        if ($this->is_public) {
            return true;
        }

        if ($token && $this->share_token === $token) {
            return true;
        }

        return false;
    }
}
