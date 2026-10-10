<?php

namespace App\Services;

use App\Jobs\NotifyHouseholdShare;
use App\Models\Household;
use App\Models\User;
use App\Notifications\Push\PushNotification;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Point d'entrée unique des pushs « sociaux » (événementiels). Garanties :
 *  - jamais à l'auteur de l'action ;
 *  - jamais la nuit : entre 21h et 8h (heure de Paris) l'envoi est différé au
 *    prochain 8h ;
 *  - les partages vers un foyer sont regroupés par (auteur, foyer) sur une
 *    fenêtre de 10 min → un seul push « Marie a partagé 37 souvenirs ».
 *
 * L'app tourne en UTC : toutes les heures « humaines » sont explicitement
 * en Europe/Paris.
 */
class PushNotifier
{
    public const TIMEZONE = 'Europe/Paris';

    /** Heures calmes : [QUIET_FROM h, QUIET_UNTIL h[ */
    public const QUIET_FROM = 21;
    public const QUIET_UNTIL = 8;

    public const SHARE_WINDOW_MINUTES = 10;

    /**
     * Instant d'envoi si l'on est en heures calmes (le prochain 8h), sinon
     * null (envoi immédiat).
     */
    public static function quietHoursDelay(?CarbonInterface $now = null): ?Carbon
    {
        $paris = Carbon::instance($now ?? now())->setTimezone(self::TIMEZONE);

        if ($paris->hour >= self::QUIET_UNTIL && $paris->hour < self::QUIET_FROM) {
            return null;
        }

        $next = $paris->copy()->setTime(self::QUIET_UNTIL, 0, 0);
        if ($paris->hour >= self::QUIET_FROM) {
            $next->addDay();
        }

        return $next;
    }

    /**
     * Envoie un push social à $recipient (sauf s'il est l'auteur), en
     * respectant les heures calmes. Le filtrage catégorie/abonnement est
     * fait par PushNotification::via().
     */
    public function social(User $recipient, PushNotification $notification, ?User $actor = null): void
    {
        if ($actor && $actor->id === $recipient->id) {
            return;
        }

        if ($delay = self::quietHoursDelay()) {
            $notification->delay($delay);
        }

        $recipient->notify($notification);
    }

    /**
     * Partage de médias vers un foyer : accumule le compteur et planifie UN
     * job différé par fenêtre. Le job (NotifyHouseholdShare) lit le total et
     * prévient les autres membres.
     */
    public function householdShare(User $actor, Household $household, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $key = self::shareKey($actor->id, $household->id);
        $ttl = now()->addMinutes(self::SHARE_WINDOW_MINUTES * 3);

        // Le store `database` n'incrémente pas une clé absente : on la crée.
        Cache::add("{$key}:count", 0, $ttl);
        Cache::increment("{$key}:count", $count);

        // add() est atomique : un seul job programmé par fenêtre.
        if (Cache::add("{$key}:scheduled", 1, $ttl)) {
            NotifyHouseholdShare::dispatch($actor->id, $household->id)
                ->delay(now()->addMinutes(self::SHARE_WINDOW_MINUTES));
        }
    }

    public static function shareKey(string $actorId, string $householdId): string
    {
        return "push:household-share:{$actorId}:{$householdId}";
    }
}
