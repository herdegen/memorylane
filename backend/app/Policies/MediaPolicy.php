<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

class MediaPolicy
{
    /**
     * Lecture : le propriétaire, ou tout média présent dans un album
     * accessible par l'utilisateur.
     */
    public function view(User $user, Media $media): bool
    {
        if ($media->user_id === $user->id) {
            return true;
        }

        return Media::accessibleBy($user)->whereKey($media->getKey())->exists();
    }

    public function update(User $user, Media $media): bool
    {
        return $media->user_id === $user->id;
    }

    public function delete(User $user, Media $media): bool
    {
        return $media->user_id === $user->id;
    }
}
