<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuestAnswer extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'question_type',
        'question_key',
        'subject_type',
        'subject_id',
        'answer_kind',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }
}
