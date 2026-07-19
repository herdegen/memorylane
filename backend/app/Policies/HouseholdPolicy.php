<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

class HouseholdPolicy
{
    /**
     * Lecture (voir le foyer, ses membres, ses médias) : réservée aux membres.
     */
    public function view(User $user, Household $household): bool
    {
        return $household->isMember($user);
    }

    /**
     * Gérer les membres (inviter / retirer) : le créateur uniquement (v2).
     */
    public function manageMembers(User $user, Household $household): bool
    {
        return $household->created_by === $user->id;
    }

    /**
     * Supprimer le foyer : le créateur uniquement.
     */
    public function delete(User $user, Household $household): bool
    {
        return $household->created_by === $user->id;
    }
}
