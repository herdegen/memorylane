<?php

namespace App\Console\Commands;

use App\Models\Person;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class CreateGuestLink extends Command
{
    protected $signature = 'guest:create {person : ID de la fiche d\'arrivée} {--days=7} {--name=Invité}';

    protected $description = 'Crée un compte invité temporaire (lecture seule fiches + arbre) et affiche son lien de connexion';

    public function handle(): int
    {
        $person = Person::findOrFail($this->argument('person'));
        $expires = now()->addDays((int) $this->option('days'));

        $user = User::create([
            'name' => $this->option('name'),
            'email' => 'invite-' . Str::lower(Str::random(10)) . '@invite.invalid',
            'password' => Hash::make(Str::random(40)),
            'guest_expires_at' => $expires,
            'guest_person_id' => $person->id,
        ]);

        $this->info('Valable jusqu\'au ' . $expires->format('d/m/Y H:i'));
        $this->line(URL::temporarySignedRoute('invite.guest', $expires, ['user' => $user->id, 'person' => $person->id]));

        return self::SUCCESS;
    }
}
