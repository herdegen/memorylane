<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Proposition de modification d'une fiche commune (cf. SharedFiches) :
 * changes = ['fields' => [champ => [ancien, nouveau]], 'remove_spouse' => [id, name]].
 */
class PersonProposal extends Model
{
    use HasUuids;

    public const PENDING = 'pending';
    public const ACCEPTED = 'accepted';
    public const REJECTED = 'rejected';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'person_id',
        'household_id',
        'user_id',
        'origin',
        'changes',
        'source',
        'person_change_id',
        'disputed_by',
        'status',
        'decided_by',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function person()
    {
        return $this->belongsTo(Person::class);
    }

    public function household()
    {
        return $this->belongsTo(Household::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function disputer()
    {
        return $this->belongsTo(User::class, 'disputed_by');
    }

    public function decider()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function messages()
    {
        return $this->hasMany(PersonProposalMessage::class, 'proposal_id')->orderBy('created_at');
    }

    public function scopePending($query)
    {
        return $query->where('status', self::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isDispute(): bool
    {
        return $this->origin === 'dispute';
    }
}
