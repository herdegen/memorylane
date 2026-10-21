<?php

namespace App\Services;

use App\Models\Person;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Fête affichée à la première visite d'un jour d'anniversaire : son propre
 * anniversaire et/ou ceux de son cercle proche (même liste que le push du
 * matin, cf. CelebrationService::birthdaysFor). Une seule fois par jour et
 * par compte, tous appareils confondus.
 */
class BirthdayGreeting
{
    public function __construct(private CelebrationService $celebrations) {}

    /**
     * @return array{date: string, self: ?array, people: list<array>}|null
     */
    public function pending(User $user): ?array
    {
        $today = today(PushNotifier::TIMEZONE);
        if (Cache::has($this->seenKey($user, $today))) {
            return null;
        }

        // Enveloppé : un « rien aujourd'hui » (null) est aussi mis en cache.
        return Cache::remember(
            "birthday-greeting:{$user->id}:{$today->toDateString()}",
            $today->copy()->endOfDay(),
            fn () => ['greeting' => $this->compute($user, $today)],
        )['greeting'];
    }

    public function markSeen(User $user): void
    {
        $today = today(PushNotifier::TIMEZONE);
        Cache::put($this->seenKey($user, $today), true, $today->copy()->endOfDay());
    }

    private function seenKey(User $user, CarbonInterface $day): string
    {
        return "birthday-greeting-seen:{$user->id}:{$day->toDateString()}";
    }

    private function compute(User $user, CarbonInterface $today): ?array
    {
        $birthdays = $this->celebrations->birthdaysFor($user, $today);
        if ($birthdays->isEmpty()) {
            return null;
        }
        (new \Illuminate\Database\Eloquent\Collection($birthdays->pluck('person')->all()))
            ->load('avatar:id')
            ->loadCount(Person::matchedFacesCountRelation());

        $own = $birthdays->first(fn ($b) => $user->person_id && $b['person']->id === $user->person_id);
        $others = $birthdays->reject(fn ($b) => $b === $own);

        return [
            'date' => $today->toDateString(),
            'self' => $own ? ['first_name' => $this->firstName($own['person']), 'age' => $own['age']] : null,
            'people' => $others->map(fn ($b) => [
                'id' => $b['person']->id,
                'name' => $b['person']->name,
                'first_name' => $this->firstName($b['person']),
                'age' => $b['age'],
                'avatar_url' => $this->avatarUrl($b['person']),
            ])->values()->all(),
        ];
    }

    private function firstName(Person $person): string
    {
        return $person->first_name ?: (strtok($person->name, ' ') ?: $person->name);
    }

    private function avatarUrl(Person $person): ?string
    {
        return $person->avatarUrl();
    }
}
