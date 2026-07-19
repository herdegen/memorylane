<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Foyer : cercle familial de comptes partageant une mémoire commune. Un compte
 * peut appartenir à plusieurs foyers. Le créateur gère les membres.
 */
class Household extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name',
        'created_by',
    ];

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
