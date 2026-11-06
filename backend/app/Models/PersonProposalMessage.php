<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Message d'une discussion sur une proposition (sources, explications). */
class PersonProposalMessage extends Model
{
    use HasUuids;

    protected $fillable = ['proposal_id', 'user_id', 'body'];

    public function proposal()
    {
        return $this->belongsTo(PersonProposal::class, 'proposal_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
