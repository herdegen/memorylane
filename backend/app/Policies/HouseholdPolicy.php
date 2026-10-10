<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * Cercles : les membres les voient ; seul un administrateur les crée, en
 * gère les membres et les supprime (activation volontaire, pas de réseau
 * social où chacun recrute).
 */
class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $user->isAdmin() || $household->isMember($user);
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function manageMembers(User $user, Household $household): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Household $household): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Household $household): bool
    {
        return $user->isAdmin();
    }
}
