<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Chapitre d'une histoire. Ses blocs, dans l'ordre :
 * {type: text, text} · {type: quote, text, cite} · {type: media, media_id, caption}.
 */
class StoryChapter extends Model
{
    use HasUuids;

    protected $fillable = ['story_id', 'position', 'title', 'when_label', 'blocks'];

    protected function casts(): array
    {
        return ['blocks' => 'array'];
    }

    public function story()
    {
        return $this->belongsTo(Story::class);
    }
}
