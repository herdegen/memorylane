<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Récit « comment on s'est connus » entre deux personnes, une version par
 * auteur. La paire est toujours stockée triée (cf. forPair / pairKey).
 */
class PersonLink extends Model
{
    use HasUuids;

    public const CONTEXTS = [
        'school' => 'À l\'école',
        'studies' => 'Pendant les études',
        'work' => 'Au travail',
        'friend' => 'Par un ami',
        'neighbors' => 'Entre voisins',
        'hobby' => 'Par un loisir',
        'family' => 'Par la famille',
        'other' => 'Autrement',
    ];

    protected $fillable = [
        'person1_id', 'person2_id', 'created_by', 'context', 'via_person_id', 'story', 'met_year',
    ];

    protected $casts = [
        'met_year' => 'integer',
    ];

    /** @return array{0: string, 1: string} */
    public static function pairKey(string $a, string $b): array
    {
        return strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
    }

    public function scopeForPair($query, string $a, string $b)
    {
        [$p1, $p2] = self::pairKey($a, $b);

        return $query->where('person1_id', $p1)->where('person2_id', $p2);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function via()
    {
        return $this->belongsTo(Person::class, 'via_person_id');
    }
}
