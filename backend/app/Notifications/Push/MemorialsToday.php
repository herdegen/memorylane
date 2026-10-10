<?php

namespace App\Notifications\Push;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * « En mémoire de Pierre, disparu il y a 10 ans aujourd'hui » — anniversaires
 * de décès du cercle proche, une notification par jour au plus. Même
 * catégorie que les anniversaires de naissance, message distinct (ton sobre).
 */
class MemorialsToday extends PushNotification
{
    public const MAX_NAMES = 3;

    /**
     * @param array<int, array{id: string, name: string, years: int}> $memorials
     * @param string $date  Jour concerné (Y-m-d), étiquette de dédoublonnage.
     */
    public function __construct(
        public array $memorials,
        public string $date,
    ) {}

    public static function category(): string
    {
        return 'birthdays';
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $url = count($this->memorials) === 1 ? "/people/{$this->memorials[0]['id']}" : '/dashboard';

        return $this->message(
            'En mémoire',
            $this->sentence(),
            $url,
            "memorials-{$this->date}",
            43200,
        );
    }

    /** « Il y a 10 ans aujourd'hui, Pierre nous quittait. » */
    public function sentence(): string
    {
        $shown = array_slice($this->memorials, 0, self::MAX_NAMES);
        $parts = array_map(function (array $m) {
            $years = $m['years'] === 1 ? '1 an' : "{$m['years']} ans";

            return "{$m['name']} ({$years})";
        }, $shown);

        if (count($shown) === 1) {
            $m = $shown[0];
            $years = $m['years'] === 1 ? 'un an' : "{$m['years']} ans";

            return "Il y a {$years} aujourd'hui, {$m['name']} nous quittait.";
        }

        $rest = count($this->memorials) - count($shown);
        if ($rest > 0) {
            $parts[] = $rest === 1 ? '1 autre proche' : "{$rest} autres proches";
        }
        $last = array_pop($parts);

        return "Une pensée aujourd'hui pour " . implode(', ', $parts) . " et {$last}.";
    }
}
