<?php

namespace App\Services;

use App\Models\DetectedFace;
use App\Models\Media;
use App\Models\MediaChange;
use App\Models\Person;
use App\Models\User;
use App\Services\Vision\FaceMatcher;
use Illuminate\Support\Facades\DB;

/**
 * Historique des corrections d'une photo. Tout membre qui la voit peut en
 * corriger la date, le titre, le lieu et les visages (MediaPolicy::editDetails) :
 * chaque correction est notée ici et peut être annulée par l'auteur de la
 * photo, l'admin ou la personne qui l'a faite.
 */
class MediaHistory
{
    /** Champs d'informations suivis (MediaController::update, quêtes). */
    public const FIELDS = ['title', 'description', 'transcription', 'taken_at', 'taken_at_precision'];

    public function __construct(private FaceMatcher $faceMatcher) {}

    /** Journalise une correction faite par le compte connecté. */
    public function log(Media $media, string $action, array $diff): ?MediaChange
    {
        $userId = auth()->id();
        if ($userId === null) {
            return null;
        }

        return MediaChange::create(['media_id' => $media->id, 'user_id' => $userId, 'action' => $action, 'diff' => $diff]);
    }

    /** Instantané des champs suivis, avant une modification. */
    public function snapshot(Media $media): array
    {
        return collect(self::FIELDS)->mapWithKeys(fn ($f) => [$f => $this->value($media, $f)])->all();
    }

    /** Journalise les champs suivis qui ont changé depuis $before. */
    public function logUpdate(Media $media, array $before): ?MediaChange
    {
        $media->refresh();
        $diff = [];
        foreach (self::FIELDS as $field) {
            $after = $this->value($media, $field);
            if (($before[$field] ?? null) !== $after) {
                $diff[$field] = [$before[$field] ?? null, $after];
            }
        }

        return $diff ? $this->log($media, 'updated', $diff) : null;
    }

    /** Position actuelle (null si la photo n'est pas placée). */
    public function position(Media $media): ?array
    {
        $meta = $media->metadata()->first();

        return $meta && $meta->latitude !== null
            ? ['latitude' => (float) $meta->latitude, 'longitude' => (float) $meta->longitude, 'altitude' => $meta->altitude !== null ? (float) $meta->altitude : null]
            : null;
    }

    /** État d'un visage, pour pouvoir y revenir. */
    public function faceState(DetectedFace $face): array
    {
        return [
            'face_id' => $face->id,
            'person_id' => $face->person_id,
            'person_name' => $face->person_id ? Person::withTrashed()->find($face->person_id)?->name : null,
            'status' => $face->status,
        ];
    }

    public function canRevert(User $user, MediaChange $change): bool
    {
        return $change->isRevertible()
            && ($user->isAdmin() || $change->user_id === $user->id || $change->media?->user_id === $user->id);
    }

    /** Défait une correction et la marque comme annulée. */
    public function revert(MediaChange $change, User $by): void
    {
        abort_unless($change->isRevertible(), 422, __('Cette correction ne peut pas être annulée.'));
        $media = $change->media;
        abort_unless($media, 404);
        $diff = $change->diff;

        DB::transaction(function () use ($change, $media, $diff, $by) {
            match ($change->action) {
                'updated' => $media->update(collect($diff)->map(fn ($pair) => $pair[0] ?? null)->all()),
                'geolocated', 'geolocation_removed' => $this->restorePosition($media, $diff['before'] ?? null),
                'face_identified', 'face_reset', 'face_dismissed' => $this->restoreFace($diff['before'] ?? []),
                'face_added' => $this->removeAddedFace($diff['face_id'] ?? null),
            };
            $change->forceFill(['reverted_at' => now(), 'reverted_by' => $by->id])->save();
        });
    }

    private function value(Media $media, string $field): ?string
    {
        $value = $media->{$field};

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : ($value === '' ? null : $value);
    }

    private function restorePosition(Media $media, ?array $before): void
    {
        $media->metadata()->updateOrCreate(['media_id' => $media->id], [
            'latitude' => $before['latitude'] ?? null,
            'longitude' => $before['longitude'] ?? null,
            'altitude' => $before['altitude'] ?? null,
        ]);
    }

    private function restoreFace(array $before): void
    {
        $face = DetectedFace::find($before['face_id'] ?? null);
        abort_unless($face, 404, __('Ce visage n’existe plus.'));
        if (! empty($before['person_id'])) {
            $this->faceMatcher->applyMatch($face, $before['person_id'], true, verified: true);
        } else {
            $this->faceMatcher->disassociate($face, $before['status'] ?? 'unmatched');
        }
    }

    private function removeAddedFace(?string $faceId): void
    {
        $face = DetectedFace::find($faceId);
        if (! $face) {
            return;
        }
        if ($face->person_id) {
            $this->faceMatcher->disassociate($face, 'unmatched');
        }
        $face->delete();
    }
}
