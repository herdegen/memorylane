<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Une ligne de l'historique de l'arbre (cf. PersonObserver).
 */
class PersonChange extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    /** Libellés français des champs suivis. */
    public const FIELD_LABELS = [
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'maiden_name' => 'Nom de naissance',
        'gender' => 'Sexe',
        'birth_date' => 'Date de naissance',
        'birth_place' => 'Lieu de naissance',
        'death_date' => 'Date de décès',
        'death_place' => 'Lieu de décès',
        'address' => 'Adresse',
        'notes' => 'Notes',
        'father_id' => 'Père',
        'mother_id' => 'Mère',
        'avatar_media_id' => 'Photo de profil',
    ];

    public const ACTION_LABELS = [
        'created' => 'Fiche créée',
        'updated' => 'Fiche modifiée',
        'deleted' => 'Fiche supprimée',
        'restored' => 'Fiche restaurée',
        'spouse_added' => 'Conjoint ajouté',
        'spouse_removed' => 'Conjoint retiré',
    ];

    protected $fillable = [
        'person_id',
        'user_id',
        'action',
        'diff',
    ];

    /**
     * Proposition en cours d'application (acceptée, ou désaccord qui remet
     * l'ancienne valeur) : les lignes d'historique écrites pendant ce temps
     * lui sont rattachées.
     */
    private static ?string $proposalContext = null;

    protected static function booted(): void
    {
        static::creating(function (PersonChange $change) {
            $change->proposal_id ??= self::$proposalContext;
        });
    }

    /** Exécute $fn en rattachant l'historique écrit à la proposition. */
    public static function forProposal(string $proposalId, callable $fn): mixed
    {
        $previous = self::$proposalContext;
        self::$proposalContext = $proposalId;
        try {
            return $fn();
        } finally {
            self::$proposalContext = $previous;
        }
    }

    /** Valeur comparable d'un champ suivi : vide = null, date = AAAA-MM-JJ. */
    public static function normalizeValue(string $field, mixed $value): mixed
    {
        if ($value === '' || $value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (in_array($field, ['birth_date', 'death_date'], true)) {
            return substr((string) $value, 0, 10);
        }

        return is_scalar($value) ? (string) $value : $value;
    }

    protected function casts(): array
    {
        return [
            'diff' => 'array',
            'reverted_at' => 'datetime',
        ];
    }

    public function person()
    {
        return $this->belongsTo(Person::class)->withTrashed();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function revertedBy()
    {
        return $this->belongsTo(User::class, 'reverted_by');
    }

    /**
     * Résumé lisible : « Date de naissance : 1950-01-01 → 1951-01-01 ».
     */
    public function summary(): string
    {
        if ($this->action !== 'updated') {
            $label = self::ACTION_LABELS[$this->action] ?? $this->action;
            $other = $this->diff['spouse_name'] ?? null;

            return $other ? "{$label} : {$other}" : $label;
        }

        return collect($this->diff ?? [])
            ->map(function ($pair, $field) {
                [$old, $new] = $pair + [null, null];
                $label = self::FIELD_LABELS[$field] ?? $field;

                if (in_array($field, ['father_id', 'mother_id'], true)) {
                    $old = $old ? Person::withTrashed()->find($old)?->name : null;
                    $new = $new ? Person::withTrashed()->find($new)?->name : null;
                }

                return $label.' : '.($old ?? '∅').' → '.($new ?? '∅');
            })
            ->implode(' · ');
    }

    public function isRevertible(): bool
    {
        if ($this->reverted_at !== null) {
            return false;
        }

        return match ($this->action) {
            'updated' => ! empty($this->diff),
            'created', 'deleted' => true,
            'spouse_added', 'spouse_removed' => ! empty($this->diff['spouse_id']),
            default => false,
        };
    }
}
