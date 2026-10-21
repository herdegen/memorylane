<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Une newsletter envoyée à un compte. `sections` : [{key, ref?}] — la
 * rubrique et ce qu'elle a montré (personne, fonctionnalité…).
 */
class NewsletterSend extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'edition', 'sections', 'subject', 'sent_at'];

    protected $casts = [
        'sections' => 'array',
        'sent_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
