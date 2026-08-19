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
        $since = now()->subDays(7);
        $count = 0;

        // Chaque destinataire ne voit que les nouveaux médias auxquels il a
        // accès (galerie privée) : décompte, prénoms et vignettes sont
        // calculés par utilisateur.
        User::each(function (User $user) use ($mediaService, $since, &$count) {
            $newMedia = Media::accessibleBy($user)
                ->with(['user', 'conversions'])
                ->where('uploaded_at', '>=', $since)
                ->orderByDesc('uploaded_at')
                ->get();

            if ($newMedia->isEmpty()) {
                return;
            }

            $uploaderNames = $newMedia->pluck('user.name')
                ->filter()
                ->unique()
                ->map(fn ($name) => explode(' ', $name)[0])
                ->values()
                ->all();

            // 4 vignettes en présigné 7 jours (durée de vie du mail) : un
            // client mail n'a pas de session, la route protégée est exclue ici.
            $samples = $newMedia->take(4)->map(fn ($media) => [
                'name' => $media->title ?: $media->original_name,
                'url' => $mediaService->thumbnailUrl($media, expirationMinutes: 60 * 24 * 7, presigned: true),
            ])->all();

            $user->notify(new WeeklyDigest($newMedia->count(), $uploaderNames, $samples));
            $count++;
        });

        if ($count === 0) {
            $this->info('Aucun nouveau média cette semaine : pas de digest.');
        } else {
            $this->info("Digest envoyé à {$count} membre(s).");
        }

        return self::SUCCESS;
    }
}
