<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GedcomImport extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'filename',
        'status',
        'parsed_data',
        'matching_decisions',
        'individuals_count',
        'families_count',
        'imported_count',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'parsed_data' => 'array',
            'matching_decisions' => 'array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
