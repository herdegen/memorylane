<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Mode grand-parent : relie (ou crée) le compte d'une fiche, active
 * l'interface simplifiée et affiche un lien de connexion sans mot de passe à
 * ouvrir une fois sur la tablette. Relancer la commande régénère le lien
 * (l'ancien cesse de fonctionner) ; --revoke le coupe sans en créer.
 */
class CreateTabletLink extends Command
{
    protected $signature = 'tablet:create
        {person : ID de la fiche du grand-parent}
        {--revoke : Désactiver le lien existant sans en créer un nouveau}';

    protected $description = 'Crée (ou régénère) le lien tablette du mode grand-parent pour une fiche';

    public function handle(): int
    {
        $person = Person::findOrFail($this->argument('person'));
        $user = User::where('person_id', $person->id)->first();

        if ($this->option('revoke')) {
            if (! $user?->tablet_token_hash) {
                $this->warn("Aucun lien tablette actif pour {$person->name}.");

                return self::SUCCESS;
            }
            $user->forceFill(['tablet_token_hash' => null, 'remember_token' => Str::random(60)])->save();
            $this->info("Lien tablette désactivé pour {$person->name} (tablettes déjà connectées déconnectées).");

            return self::SUCCESS;
        }

        // Le mode grand-parent est un mode FAMILLE : pas de tablette pour une
        // fiche d'un cercle d'amis.
        if (! $person->in_family_directory) {
            $this->error("{$person->name} n'est pas dans la famille : le mode grand-parent est réservé aux fiches de la famille.");

            return self::FAILURE;
        }

        if (! $user) {
            $user = User::create([
                'name' => $person->first_name ?: $person->name,
                'email' => 'tablette-' . Str::lower(Str::random(10)) . '@tablette.invalid',
                'password' => Hash::make(Str::random(40)),
                'person_id' => $person->id,
            ]);
            $this->line("Compte créé pour {$person->name}.");
        }

        $token = Str::random(48);
        // Nouveau remember_token : une tablette connectée avec l'ancien lien est déconnectée.
        $user->forceFill([
            'simple_mode' => true,
            'tablet_token_hash' => hash('sha256', $token),
            'remember_token' => Str::random(60),
        ])->save();

        $this->info("Mode grand-parent activé pour {$person->name}. Lien à ouvrir une fois sur la tablette :");
        $this->line(route('tablet.login', $token));

        return self::SUCCESS;
    }
}
