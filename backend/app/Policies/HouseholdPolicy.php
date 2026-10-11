<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * Cercles : les membres les voient ; seul un administrateur les crée, les
 * règle, en retire des membres et les supprime. Faire entrer quelqu'un :
 * l'admin, ou tout membre si le cercle est « ouvert » (invite_policy).
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

    /** Ajouter un compte : l'admin, ou tout membre si le cercle l'autorise. */
    public function addMembers(User $user, Household $household): bool
    {
        return $household->allowsInvitesFrom($user);
    }

    /** Retirer des membres, gérer les fiches du cercle : l'admin. */
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
