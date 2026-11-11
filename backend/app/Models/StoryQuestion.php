<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Question ouverte d'une histoire, à laquelle la famille peut répondre. */
class StoryQuestion extends Model
{
    use HasUuids;

    protected $fillable = ['story_id', 'position', 'text'];

    public function story()
    {
        return $this->belongsTo(Story::class);
    }

    public function notes()
    {
        return $this->hasMany(StoryNote::class, 'question_id');
    }
}
