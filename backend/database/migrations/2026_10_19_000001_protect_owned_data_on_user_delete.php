<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Supprimer un compte effaçait en cascade tout ce qu'il possédait : photos,
 * albums, fiches de l'arbre qu'il avait saisies, moments, récits « comment on
 * s'est connus » et cercles créés. Désormais la base REFUSE la suppression
 * tant que ces données lui appartiennent : un départ passe par la procédure
 * qui les transfère ou les retire d'abord (DepartureService).
 */
return new class extends Migration
{
    private const OWNED = [
        ['media', 'user_id', 'media_user_id_foreign'],
        ['albums', 'user_id', 'albums_user_id_foreign'],
        ['people', 'user_id', 'people_user_id_foreign'],
        ['life_events', 'user_id', 'life_events_user_id_foreign'],
        ['person_links', 'created_by', 'person_links_created_by_foreign'],
        ['households', 'created_by', 'households_created_by_foreign'],
    ];

    public function up(): void
    {
        foreach (self::OWNED as [$table, $column, $name]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES users(id) ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        foreach (self::OWNED as [$table, $column, $name]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY ({$column}) REFERENCES users(id) ON DELETE CASCADE");
        }
    }
};
