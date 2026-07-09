<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UploadSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'upload_id',
        's3_key',
        'original_name',
        'mime_type',
        'size',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
