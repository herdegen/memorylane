<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\User;
use App\Notifications\Push\BirthdaysToday;
use App\Notifications\Push\MemorialsToday;
use App\Services\CelebrationService;
use App\Services\PushNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

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

        User::realPeople()->each(function (User $user) use ($celebrations, $today, &$sent) {
            if (! $user->wantsPush('birthdays')) {
                return;
            }

            // Proches de la famille (parenté) + fiches de ses cercles d'amis
            // (sans l'âge : entre amis, on fête, on ne compte pas).
            $family = $celebrations->birthdaysOn($today, $celebrations->closeCircleQuery($user)->where('in_family_directory', true));
            $friendIds = DB::table('household_person')->whereIn('household_id', $user->friendCircleIds())->pluck('person_id');
            $friends = $friendIds->isEmpty()
                ? collect()
                : $celebrations->birthdaysOn($today, Person::visibleTo($user)->whereIn('people.id', $friendIds)->where('in_family_directory', false));
            $birthdays = $family->map(fn ($b) => [...$b, 'friend' => false])
                ->concat($friends->map(fn ($b) => [...$b, 'friend' => true]))
                ->unique(fn ($b) => $b['person']->id);
            if ($birthdays->isNotEmpty()) {
                $user->notify(new BirthdaysToday(
                    $birthdays->map(fn ($b) => [
                        'id' => $b['person']->id,
                        'name' => $b['person']->name,
                        'age' => $b['friend'] ? null : $b['age'],
                    ])->values()->all(),
                    $today->toDateString(),
                ));
                $sent++;
            }

            // Anniversaires de décès : notification distincte, ton sobre.
            // Famille seulement (fiches du répertoire familial).
            $memorials = $celebrations->memorialsOn($today, $celebrations->closeCircleQuery($user)->where('in_family_directory', true));
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
