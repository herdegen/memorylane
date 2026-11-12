<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** « Je sais quelque chose » : un ajout d'un lecteur à une histoire, signé. */
class StoryNote extends Model
{
    use HasUuids;

    protected $fillable = ['story_id', 'question_id', 'user_id', 'body'];

    public function story()
    {
        return $this->belongsTo(Story::class);
    }

    public function question()
    {
        return $this->belongsTo(StoryQuestion::class, 'question_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
