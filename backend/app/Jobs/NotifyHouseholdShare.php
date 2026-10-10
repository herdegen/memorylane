<?php

namespace App\Jobs;

use App\Models\Household;
use App\Models\User;
use App\Notifications\Push\MediaSharedToHousehold;
use App\Notifications\Push\PushNotification;
use App\Services\PushNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/**
 * Fin de la fenêtre de regroupement d'un partage vers un foyer : lit le
 * total accumulé par PushNotifier::householdShare() et prévient les autres
 * membres en un seul push. Ne porte que des identifiants (pas de modèles).
 */
class NotifyHouseholdShare implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public function __construct(
        public string $actorId,
        public string $householdId,
    ) {}

    public function handle(PushNotifier $push): void
    {
        $key = PushNotifier::shareKey($this->actorId, $this->householdId);

        // D'abord libérer la fenêtre : un partage qui arrive maintenant
        // replanifie proprement un nouveau job au lieu d'être perdu.
        Cache::forget("{$key}:scheduled");
        $count = (int) Cache::pull("{$key}:count", 0);

        $actor = User::find($this->actorId);
        $household = Household::with('members')->find($this->householdId);

        if ($count === 0 || ! $actor || ! $household) {
            return;
        }

        $first = PushNotification::firstName($actor->name);

        foreach ($household->members as $member) {
            $push->social(
                $member,
                new MediaSharedToHousehold($first, $household->name, $household->id, $count),
                $actor,
            );
        }
    }
}
