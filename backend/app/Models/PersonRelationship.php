<?php

namespace App\Models;

use App\Observers\PersonRelationshipObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[ObservedBy(PersonRelationshipObserver::class)]
class PersonRelationship extends Model
{
    use HasUuids;

    protected $fillable = [
        'person1_id',
        'person2_id',
        'type',
        'start_date',
        'end_date',
        'start_place',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function person1()
    {
        return $this->belongsTo(Person::class, 'person1_id');
    }

    public function person2()
    {
        return $this->belongsTo(Person::class, 'person2_id');
    }
}
