<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\AlbumAccess;
use App\Models\User;

class AlbumPolicy
{
    /**
     * Lecture : selon les règles d'accès (owner / public / accordé / tagué).
     */
    public function view(User $user, Album $album): bool
    {
        return $album->isAccessibleBy($user);
    }

    public function update(User $user, Album $album): bool
    {
        return $album->user_id === $user->id;
    }

    public function delete(User $user, Album $album): bool
    {
        return $album->user_id === $user->id;
    }

    /**
     * Ajouter/retirer/réordonner des médias, couverture, géolocalisation.
     */
    public function manageMedia(User $user, Album $album): bool
    {
        return $album->user_id === $user->id;
    }

    /**
     * Basculer l'album en public : propriétaire uniquement.
     */
    public function setPublic(User $user, Album $album): bool
    {
        return $album->user_id === $user->id;
    }

    /**
     * Accorder un accès : toute personne ayant déjà accès (délégation
     * récursive). Ne permet jamais de rendre public (cf. setPublic).
     */
    public function grantAccess(User $user, Album $album): bool
    {
        return $album->isAccessibleBy($user);
    }

    /**
     * Révoquer un accès : le propriétaire révoque n'importe qui ; sinon on ne
     * peut révoquer que les accès qu'on a soi-même accordés.
     */
    public function revokeAccess(User $user, Album $album, ?AlbumAccess $access = null): bool
    {
        if ($album->user_id === $user->id) {
            return true;
        }

        return $access !== null && $access->granted_by === $user->id;
    }
}
