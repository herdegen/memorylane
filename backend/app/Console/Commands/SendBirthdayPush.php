<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Push\BirthdaysToday;
use App\Notifications\Push\MemorialsToday;
use App\Services\CelebrationService;
use App\Services\PushNotifier;
use Illuminate\Console\Command;

/**
 * Push « anniversaires du jour » : une notification par utilisateur pour
 * les anniversaires de son cercle proche, une autre (sobre) pour les
 * anniversaires de décès. Planifié à 9h (Paris) ;
 * ne part que s'il y a au moins un anniversaire et que l'utilisateur a activé
 * la catégorie sur un appareil.
 */
class SendBirthdayPush extends Command
{
    protected $signature = 'memorylane:birthday-push';

    protected $description = "Envoie le push « anniversaires du jour » aux membres qui l'ont activé";

    public function handle(CelebrationService $celebrations): int
    {
        $today = today(PushNotifier::TIMEZONE);
        $sent = 0;

        User::each(function (User $user) use ($celebrations, $today, &$sent) {
            if (! $user->wantsPush('birthdays')) {
                return;
            }

            $birthdays = $celebrations->birthdaysOn($today, $celebrations->closeCircleQuery($user));
            if ($birthdays->isNotEmpty()) {
                $user->notify(new BirthdaysToday(
                    $birthdays->map(fn ($b) => [
                        'id' => $b['person']->id,
                        'name' => $b['person']->name,
                        'age' => $b['age'],
                    ])->values()->all(),
                    $today->toDateString(),
                ));
                $sent++;
            }

            // Anniversaires de décès : notification distincte, ton sobre.
            $memorials = $celebrations->memorialsOn($today, $celebrations->closeCircleQuery($user));
            if ($memorials->isNotEmpty()) {
                $user->notify(new MemorialsToday(
                    $memorials->map(fn ($m) => [
                        'id' => $m['person']->id,
                        'name' => $m['person']->name,
                        'years' => $m['years'],
                    ])->values()->all(),
                    $today->toDateString(),
                ));
                $sent++;
            }
        });

        $this->info($sent === 0
            ? "Aucun anniversaire à annoncer aujourd'hui."
            : "{$sent} push anniversaires / souvenirs envoyé(s).");

        return self::SUCCESS;
    }
}
