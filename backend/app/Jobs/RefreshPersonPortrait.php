<?php

namespace App\Jobs;

use App\Models\Person;
use App\Services\StoredPortraitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Refait le portrait stocké d'une fiche (sans effet si sa source n'a pas
 * changé). Unique par fiche : une rafale d'associations de visages ne
 * déclenche qu'un rendu.
 */
class RefreshPersonPortrait implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 120;

    public function __construct(public string $personId) {}

    public function uniqueId(): string
    {
        return $this->personId;
    }

    public function handle(StoredPortraitService $portraits): void
    {
        if ($person = Person::withoutGlobalScopes()->find($this->personId)) {
            $portraits->refresh($person);
        }
    }

    /** À appeler depuis les événements modèle (après commit de la transaction). */
    public static function for(?string ...$personIds): void
    {
        foreach (array_unique(array_filter($personIds)) as $id) {
            static::dispatch($id)->afterCommit()->delay(now()->addSeconds(5));
        }
    }
}
