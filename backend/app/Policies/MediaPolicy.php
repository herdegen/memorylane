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

        if (Media::accessibleBy($user)->whereKey($media->getKey())->exists()) {
            return true;
        }

        // Vidéo et clips se répondent : qui voit l'un peut ouvrir l'autre
        // (« Extrait de… », liste des clips) sans tomber sur un refus.
        if ($media->source_media_id !== null) {
            return Media::accessibleBy($user)->whereKey($media->source_media_id)->exists();
        }

        return $media->is_source && Media::accessibleBy($user)->where('source_media_id', $media->getKey())->exists();
    }

    public function update(User $user, Media $media): bool
    {
        return $media->user_id === $user->id;
    }

    /**
     * Corriger les détails (date, titre, description, lieu, visages) : tout
     * compte qui voit la photo, sauf l'invité temporaire (lecture seule).
     * Chaque correction est journalisée et annulable (MediaHistory).
     */
    public function editDetails(User $user, Media $media): bool
    {
        if ($user->isGuest()) {
            return false;
        }

        return $media->user_id === $user->id || $user->isAdmin() || $this->view($user, $media);
    }

    public function delete(User $user, Media $media): bool
    {
        return $media->user_id === $user->id;
    }
}
