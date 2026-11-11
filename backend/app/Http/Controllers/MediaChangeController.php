<?php

namespace App\Http\Controllers;

use App\Models\Media;
use App\Models\MediaChange;
use App\Models\User;
use App\Services\MediaHistory;
use Illuminate\Http\Request;

/**
 * Historique des corrections d'une photo (page de la photo) et annulation.
 */
class MediaChangeController extends Controller
{
    public function revert(Request $request, MediaChange $mediaChange, MediaHistory $history)
    {
        abort_unless($history->canRevert($request->user(), $mediaChange), 403, __('Seuls l’auteur de la photo, l’admin ou la personne qui a fait la correction peuvent l’annuler.'));
        $history->revert($mediaChange, $request->user());

        return response()->json(['message' => __('Correction annulée.')]);
    }

    /** Les dernières corrections d'une photo, prêtes à afficher. */
    public static function payload(Media $media, User $viewer): array
    {
        $history = app(MediaHistory::class);

        return MediaChange::where('media_id', $media->id)
            ->with(['user:id,name', 'revertedBy:id,name'])
            ->latest('created_at')
            ->limit(50)
            ->get()
            ->map(fn (MediaChange $c) => [
                'id' => $c->id,
                'action' => $c->action,
                'summary' => self::summary($c),
                'by' => $c->user ? (strtok($c->user->name, ' ') ?: $c->user->name) : __('Quelqu’un'),
                'at' => $c->created_at?->toIso8601String(),
                'reverted' => $c->reverted_at !== null,
                'reverted_by' => $c->revertedBy ? (strtok($c->revertedBy->name, ' ') ?: $c->revertedBy->name) : null,
                'can_revert' => $history->canRevert($viewer, $c),
            ])->values()->all();
    }

    private static function summary(MediaChange $c): string
    {
        $d = $c->diff ?? [];

        return match ($c->action) {
            'updated' => collect($d)->map(fn ($pair, $field) => __(':field : :old → :new', [
                'field' => match ($field) { 'taken_at' => __('Date'), 'title' => __('Titre'), default => __('Description') },
                'old' => self::show($field, $pair[0] ?? null),
                'new' => self::show($field, $pair[1] ?? null),
            ]))->join(' · '),
            'geolocated' => empty($d['before']) ? __('Lieu placé sur la carte') : __('Lieu déplacé sur la carte'),
            'geolocation_removed' => __('Lieu retiré'),
            'face_identified' => ! empty($d['before']['person_name'])
                ? __('Visage : :old → :new', ['old' => $d['before']['person_name'], 'new' => $d['after']['person_name'] ?? '?'])
                : __('Visage nommé : :name', ['name' => $d['after']['person_name'] ?? '?']),
            'face_reset' => __('Identification retirée : :name', ['name' => $d['before']['person_name'] ?? '?']),
            'face_dismissed' => __('Cadre ignoré (pas un visage)'),
            'face_added' => __('Visage ajouté à la main'),
            default => $c->action,
        };
    }

    private static function show(string $field, ?string $value): string
    {
        if ($value === null || $value === '') {
            return __('vide');
        }
        if ($field === 'taken_at') {
            return \Illuminate\Support\Carbon::parse($value)->translatedFormat('j F Y');
        }

        return '« '.\Illuminate\Support\Str::limit($value, 60).' »';
    }
}
