<?php

namespace App\Services;

use App\Models\Person;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Anniversaires de naissance et de décès : logique partagée entre le
 * tableau de bord (fenêtre de 15 jours) et les push quotidiens du jour J
 * (cercle proche de l'utilisateur).
 */
class CelebrationService
{
    /**
     * Fiches généalogiques anciennes sans date de décès : au-delà d'un âge
     * plausible, on ne souhaite pas l'anniversaire.
     */
    public const MAX_PLAUSIBLE_AGE = 105;

    /**
     * Distance de parenté maximale du cercle « proche » notifié par push
     * (parents, enfants, frères/sœurs, grands-parents, oncles/tantes,
     * cousins germains… ≤ 3 liens).
     */
    public const PUSH_CIRCLE_MAX_DISTANCE = 3;

    /**
     * Anniversaires de décès : seulement les disparitions de moins de 50 ans
     * (des proches dont on se souvient), pas les ancêtres de la généalogie.
     */
    public const MEMORIAL_MAX_YEARS = 50;

    public function __construct(
        protected GenealogyService $genealogy,
    ) {}

    /**
     * Anniversaires (personnes vivantes, âge plausible) dans les $windowDays
     * prochains jours, à partir de $people (défaut : toutes les personnes).
     *
     * @return Collection<int, array{person: Person, age: int, days_until: int}>
     */
    public function upcomingBirthdays(CarbonInterface $today, int $windowDays, ?Builder $people = null): Collection
    {
        $today = Carbon::instance($today)->startOfDay();

        $query = ($people ?? Person::query())
            ->whereNotNull('birth_date')
            ->whereNull('death_date');

        return $query->get()
            ->map(function (Person $person) use ($today, $windowDays) {
                $days = self::daysUntilAnniversary($person->birth_date, $today, $windowDays);
                if ($days === null) {
                    return null;
                }
                $age = $today->copy()->addDays($days)->year - $person->birth_date->year;

                return $age > self::MAX_PLAUSIBLE_AGE
                    ? null
                    : ['person' => $person, 'age' => $age, 'days_until' => $days];
            })
            ->filter()
            ->values();
    }

    /**
     * Anniversaires de décès (disparitions de moins de MEMORIAL_MAX_YEARS
     * ans) dans les $windowDays prochains jours. Le jour même du décès
     * (0 an) n'est pas un anniversaire.
     *
     * @return Collection<int, array{person: Person, years: int, days_until: int}>
     */
    public function upcomingMemorials(CarbonInterface $today, int $windowDays, ?Builder $people = null): Collection
    {
        $today = Carbon::instance($today)->startOfDay();

        return ($people ?? Person::query())
            ->whereNotNull('death_date')
            ->where('death_date', '>', $today->copy()->subYears(self::MEMORIAL_MAX_YEARS + 1))
            ->get()
            ->map(function (Person $person) use ($today, $windowDays) {
                $days = self::daysUntilAnniversary($person->death_date, $today, $windowDays);
                if ($days === null) {
                    return null;
                }
                $years = $today->copy()->addDays($days)->year - $person->death_date->year;

                return $years < 1 || $years > self::MEMORIAL_MAX_YEARS
                    ? null
                    : ['person' => $person, 'years' => $years, 'days_until' => $days];
            })
            ->filter()
            ->values();
    }

    /** Anniversaires de décès du jour $day uniquement. */
    public function memorialsOn(CarbonInterface $day, ?Builder $people = null): Collection
    {
        return $this->upcomingMemorials($day, 0, $people);
    }

    /** Anniversaires du jour $day uniquement. */
    public function birthdaysOn(CarbonInterface $day, ?Builder $people = null): Collection
    {
        return $this->upcomingBirthdays($day, 0, $people);
    }

    /**
     * Personnes du cercle proche de l'utilisateur : distance de parenté ≤ 3
     * depuis sa fiche « moi ». Repli sans fiche liée (ou isolée dans
     * l'arbre) : les personnes présentes sur des médias qu'il peut voir.
     */
    public function closeCircleQuery(User $user): Builder
    {
        $distance = $this->genealogy->proximity($user->person_id)['distance'];
        $ids = array_keys(array_filter($distance, fn ($d) => $d <= self::PUSH_CIRCLE_MAX_DISTANCE));

        if ($ids !== []) {
            return Person::whereIn('id', $ids);
        }

        return Person::whereHas('media', fn ($q) => $q->accessibleBy($user));
    }

    /**
     * Jours restants avant la prochaine occurrence (mois + jour) de la date,
     * ou null au-delà de la fenêtre.
     */
    public static function daysUntilAnniversary(Carbon $date, Carbon $today, int $windowDays): ?int
    {
        // Année courante d'abord (29 février : Carbon reporte au 1er mars).
        $next = $date->copy()->year($today->year)->startOfDay();
        if ($next->lt($today)) {
            $next->addYear();
        }

        $days = (int) $today->diffInDays($next);

        return $days <= $windowDays ? $days : null;
    }
}
