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
            'updated' => self::updatedSummary($c),
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

    /** « Date : années 1970 → 12 mars 1972 · Titre : … » */
    private static function updatedSummary(MediaChange $c): string
    {
        $d = $c->diff ?? [];
        $parts = [];
        if (isset($d['taken_at']) || isset($d['taken_at_precision'])) {
            $media = $c->media;
            $dates = $d['taken_at'] ?? [$media?->taken_at?->format('Y-m-d H:i:s'), $media?->taken_at?->format('Y-m-d H:i:s')];
            $precisions = $d['taken_at_precision'] ?? [$media?->taken_at_precision, $media?->taken_at_precision];
            $parts[] = __(':field : :old → :new', [
                'field' => __('Date'),
                'old' => self::date($dates[0] ?? null, $precisions[0] ?? null),
                'new' => self::date($dates[1] ?? null, $precisions[1] ?? null),
            ]);
        }
        foreach (['title' => __('Titre'), 'description' => __('Description'), 'transcription' => __('Transcription')] as $field => $label) {
            if (isset($d[$field])) {
                $parts[] = __(':field : :old → :new', ['field' => $label, 'old' => self::text($d[$field][0] ?? null), 'new' => self::text($d[$field][1] ?? null)]);
            }
        }

        return implode(' · ', $parts);
    }

    /** Date à sa précision : « 12 mars 1972 », « mars 1972 », « 1972 », « années 1970 ». */
    public static function date(?string $value, ?string $precision): string
    {
        if ($value === null || $value === '') {
            return __('vide');
        }
        $d = \Illuminate\Support\Carbon::parse($value);

        return match ($precision) {
            'decade' => __('années :decade', ['decade' => intdiv($d->year, 10) * 10]),
            'year' => (string) $d->year,
            'month' => $d->translatedFormat('F Y'),
            default => $d->translatedFormat('j F Y'),
        };
    }

    private static function text(?string $value): string
    {
        return $value === null || $value === '' ? __('vide') : '« '.\Illuminate\Support\Str::limit($value, 60).' »';
    }
}
