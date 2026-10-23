<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * « Aujourd'hui : anniversaire de Mamie Jeanne (82 ans) et de Léo (7 ans) »
 * — une seule notification par jour, tous les anniversaires du cercle proche.
 */
class BirthdaysToday extends PushNotification
{
    /** Au-delà, on résume (« et N autres ») : payload Web Push limité à 4 Ko. */
    public const MAX_NAMES = 5;

    /**
     * @param array<int, array{id: string, name: string, age: int}> $birthdays
     * @param string $date  Jour concerné (Y-m-d), sert d'étiquette de dédoublonnage.
     */
    public function __construct(
        public array $birthdays,
        public string $date,
    ) {}

    public static function category(): string
    {
        return 'birthdays';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $count = count($this->birthdays);
        $url = $count === 1 ? "/people/{$this->birthdays[0]['id']}" : '/dashboard';

        // 12 h : un téléphone éteint toute la journée n'a pas à l'apprendre demain.
        return $this->message(
            $count === 1 ? __('Anniversaire aujourd\'hui') : __('Anniversaires aujourd\'hui'),
            __('Aujourd\'hui : anniversaire :people', ['people' => $this->peopleSentence()]),
            $url,
            "birthdays-{$this->date}",
            43200,
        );
    }

    /** « de Mamie Jeanne (82 ans), de Paul (40 ans) et de Léo (7 ans) ». */
    public function peopleSentence(): string
    {
        $shown = array_slice($this->birthdays, 0, self::MAX_NAMES);
        $parts = array_map(function (array $b) {
            // Entre amis, pas d'âge (age null).
            if (($b['age'] ?? null) === null) {
                return __('de :name', ['name' => $b['name']]);
            }

            return trans_choice('de :name (:count an)|de :name (:count ans)', $b['age'], ['name' => $b['name']]);
        }, $shown);

        $rest = count($this->birthdays) - count($shown);
        if ($rest > 0) {
            $parts[] = trans_choice('d\'une autre personne|de :count autres personnes', $rest);
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return __(':list et :last', ['list' => implode(', ', $parts), 'last' => $last]);
    }
}
