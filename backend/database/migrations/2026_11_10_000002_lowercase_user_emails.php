<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les adresses sont désormais enregistrées en minuscules (User::email()) et la
 * connexion compare en minuscules : on normalise les comptes existants. Une
 * adresse dont la version en minuscules existe déjà est laissée telle quelle
 * (doublon à régler à la main).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE users u SET email = lower(trim(u.email))
            WHERE u.email <> lower(trim(u.email))
              AND NOT EXISTS (
                  SELECT 1 FROM users o WHERE o.id <> u.id AND o.email = lower(trim(u.email))
              )
        SQL);
    }

    public function down(): void
    {
        // Rien à défaire : la casse d'origine n'a pas de sens.
    }
};
