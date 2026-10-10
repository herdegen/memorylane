<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Cercle (foyer) : groupe de comptes partageant une mémoire commune, de type
 * famille ou amis. Un compte peut appartenir à plusieurs cercles ; seuls les
 * administrateurs les créent et en gèrent les membres.
 */
class Household extends Model
{
    use HasFactory, HasUuids;

    public const KIND_FAMILY = 'family';
    public const KIND_FRIENDS = 'friends';

    protected $fillable = [
        'name',
        'kind',
        'created_by',
        'is_default',
    ];

    public function isFriends(): bool
    {
        return $this->kind === self::KIND_FRIENDS;
    }

    /**
     * Fiches rattachées à ce cercle (cercles d'amis : visibles des membres).
     */
    public function people()
    {
        return $this->belongsToMany(Person::class, 'household_person')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * Foyer d'accueil : rejoint automatiquement par toute personne invitée.
     */
    public static function welcome(): ?self
    {
        return static::where('is_default', true)->first();
    }

    /**
     * Désigne ce foyer comme foyer d'accueil (un seul à la fois).
     */
    public function makeWelcome(): void
    {
        static::where('is_default', true)->whereKeyNot($this->id)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }

    /**
     * Comptes membres du foyer.
     */
    public function members()
    {
        return $this->belongsToMany(User::class, 'household_user')
            ->withPivot('invited_by')
            ->withTimestamps();
    }

    /**
     * Créateur du foyer (gère les membres, peut le supprimer).
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Médias partagés dans ce foyer (rempli en phase 2b).
     */
    public function media()
    {
        return $this->belongsToMany(Media::class, 'household_media')
            ->withPivot('added_by')
            ->withTimestamps();
    }

    public function isMember(?User $user): bool
    {
        return $user !== null && $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Foyers dont l'utilisateur est membre.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('members', fn ($m) => $m->whereKey($user->id));
    }
}
