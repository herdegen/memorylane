<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;

/**
 * Cercles : les membres les voient. Un cercle famille est créé par l'admin ;
 * un cercle d'amis par n'importe quel membre, qui en devient le responsable
 * (règle, retire des membres, supprime — l'admin aussi). Faire entrer
 * quelqu'un : le responsable, ou tout membre si le cercle est « ouvert ».
 */
class HouseholdPolicy
{
    public function view(User $user, Household $household): bool
    {
        return $user->isAdmin() || $household->isMember($user);
    }

    /** Créer : tout membre (son cercle d'amis) ; un cercle famille : l'admin. */
    public function create(User $user): bool
    {
        return ! $user->isGuest() && ! $user->simple_mode;
    }

    /** Ajouter un compte : l'admin, ou tout membre si le cercle l'autorise. */
    public function addMembers(User $user, Household $household): bool
    {
        return $household->allowsInvitesFrom($user);
    }

    /** Retirer des membres, gérer les fiches du cercle : le responsable. */
    public function manageMembers(User $user, Household $household): bool
    {
        return $household->isManagedBy($user);
    }

    public function update(User $user, Household $household): bool
    {
        return $household->isManagedBy($user);
    }

    public function delete(User $user, Household $household): bool
    {
        return $household->isManagedBy($user);
    }
}
