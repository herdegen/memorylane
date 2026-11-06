<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Multi-familles : une fiche de famille appartient désormais à ses cercles
 * famille. Les fiches déjà dans le répertoire familial rejoignent les cercles
 * famille existants — rien ne change pour les familles déjà là (une seule en
 * pratique) ; les nouvelles fiches rejoindront le cercle de leur auteur.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            INSERT INTO household_person (household_id, person_id, added_by, created_at, updated_at)
            SELECT h.id, p.id, h.created_by, NOW(), NOW()
            FROM people p
            CROSS JOIN households h
            WHERE p.in_family_directory = true AND h.kind = 'family'
            ON CONFLICT (household_id, person_id) DO NOTHING
        SQL);
    }

    public function down(): void
    {
        // Rien : retirer ces rattachements masquerait les fiches.
    }
};
