<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Services\StoredPortraitService;
use Illuminate\Console\Command;

/**
 * (Re)fait les portraits stockés de toutes les fiches : rattrapage initial,
 * et filet de sécurité nocturne (une modification passée hors événements
 * modèle est rattrapée). Sans effet pour une fiche dont la source n'a pas changé.
 */
class RefreshPortraits extends Command
{
    protected $signature = 'people:refresh-portraits';

    protected $description = 'Met à jour les portraits stockés (avatars) de toutes les fiches';

    public function handle(StoredPortraitService $portraits): int
    {
        $changed = 0;
        $total = 0;

        Person::query()->orderBy('id')->chunkById(100, function ($people) use ($portraits, &$changed, &$total) {
            foreach ($people as $person) {
                $total++;
                if ($portraits->refresh($person)) {
                    $changed++;
                }
            }
        });

        $this->info("{$changed} portrait(s) mis à jour sur {$total} fiche(s).");

        return self::SUCCESS;
    }
}
