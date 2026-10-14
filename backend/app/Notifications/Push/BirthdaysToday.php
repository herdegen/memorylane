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
            $count === 1 ? "Anniversaire aujourd'hui" : "Anniversaires aujourd'hui",
            "Aujourd'hui : anniversaire {$this->peopleSentence()}",
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
                return "de {$b['name']}";
            }
            $age = $b['age'] === 1 ? '1 an' : "{$b['age']} ans";

            return "de {$b['name']} ({$age})";
        }, $shown);

        $rest = count($this->birthdays) - count($shown);
        if ($rest > 0) {
            $parts[] = $rest === 1 ? "d'une autre personne" : "de {$rest} autres personnes";
        }

        if (count($parts) === 1) {
            return $parts[0];
        }

        $last = array_pop($parts);

        return implode(', ', $parts) . ' et ' . $last;
    }
}
