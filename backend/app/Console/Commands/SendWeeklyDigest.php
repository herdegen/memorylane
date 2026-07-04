<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Models\User;
use App\Notifications\WeeklyDigest;
use App\Services\MediaService;
use Illuminate\Console\Command;

class SendWeeklyDigest extends Command
{
    protected $signature = 'memorylane:weekly-digest';

    protected $description = 'Envoie le résumé hebdomadaire des nouveaux médias à toute la famille';

    public function handle(MediaService $mediaService): int
    {
        $newMedia = Media::with(['user', 'conversions'])
            ->where('uploaded_at', '>=', now()->subDays(7))
            ->orderByDesc('uploaded_at')
            ->get();

        if ($newMedia->isEmpty()) {
            $this->info('Aucun nouveau média cette semaine : pas de digest.');
            return self::SUCCESS;
        }

        $uploaderNames = $newMedia->pluck('user.name')
            ->filter()
            ->unique()
            ->map(fn ($name) => explode(' ', $name)[0])
            ->values()
            ->all();

        // 4 vignettes, URLs signées valables 7 jours (durée de vie du mail)
        $samples = $newMedia->take(4)->map(function ($media) use ($mediaService) {
            $thumb = $media->conversions->firstWhere('conversion_name', 'small')
                ?? $media->conversions->firstWhere('conversion_name', 'thumbnail');

            return [
                'name' => $media->title ?: $media->original_name,
                'url' => $mediaService->getSignedUrl($media, $thumb?->file_path, 60 * 24 * 7),
            ];
        })->all();

        $count = 0;
        User::each(function (User $user) use ($newMedia, $uploaderNames, $samples, &$count) {
            $user->notify(new WeeklyDigest($newMedia->count(), $uploaderNames, $samples));
            $count++;
        });

        $this->info("Digest envoyé à {$count} membre(s) — {$newMedia->count()} nouveau(x) média(s).");

        return self::SUCCESS;
    }
}
