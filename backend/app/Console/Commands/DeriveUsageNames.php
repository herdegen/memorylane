<?php

namespace App\Console\Commands;

use App\Models\Person;
use Illuminate\Console\Command;

/**
 * Le GEDCOM enregistre chaque personne sous son nom de NAISSANCE (SURN). Pour
 * les femmes mariées, on déduit le nom d'USAGE du mari (via les relations
 * conjoint) : last_name = nom du mari, maiden_name = nom de naissance.
 * Le nom d'affichage (personLabel) devient « Prénom NomMari (NomNaissance) ».
 *
 * Dry-run par défaut ; `--apply` pour écrire. N'écrase jamais une fiche qui a
 * déjà un maiden_name (édition manuelle ou dérivation déjà faite).
 */
class DeriveUsageNames extends Command
{
    protected $signature = 'people:derive-usage-names {--apply : Applique réellement les changements}';

    protected $description = 'Déduit le nom d\'usage des femmes mariées à partir du nom du mari';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $planned = [];

        Person::query()
            ->where('gender', 'F')
            ->whereNull('maiden_name')
            ->whereNotNull('last_name')
            ->whereNotNull('first_name')
            ->with(['spousesAsFirst', 'spousesAsSecond'])
            ->chunkById(100, function ($chunk) use (&$planned) {
                foreach ($chunk as $person) {
                    $husband = $person->spouses->first(
                        fn ($s) => $s->gender === 'M' && filled($s->last_name)
                    );
                    if (! $husband) {
                        continue;
                    }
                    $married = $husband->last_name;
                    if ($married === $person->last_name) {
                        continue; // déjà le même nom
                    }
                    $planned[] = [$person, $person->last_name, $married];
                }
            });

        $this->info(count($planned) . ' fiche(s) concernée(s).');
        foreach (array_slice($planned, 0, 15) as [$person, $birth, $married]) {
            $this->line("  {$person->first_name} : {$birth} → {$married} ({$birth})");
        }
        if (count($planned) > 15) {
            $this->line('  … (' . (count($planned) - 15) . ' de plus)');
        }

        if (! $apply) {
            $this->warn('Dry-run : rien de modifié. Relancez avec --apply pour appliquer.');
            return self::SUCCESS;
        }

        foreach ($planned as [$person, $birth, $married]) {
            $person->maiden_name = $birth;   // nom de naissance
            $person->last_name = $married;   // nom d'usage (mari)
            $person->save();                 // le hook `saving` recompose `name`
        }

        $this->info(count($planned) . ' fiche(s) mise(s) à jour.');

        return self::SUCCESS;
    }
}
