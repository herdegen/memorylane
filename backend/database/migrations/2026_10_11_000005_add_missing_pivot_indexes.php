<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Index manquants sur la seconde colonne des tables pivot : la clé primaire
 * composée (a, b) ne sert pas les recherches par b seul (« les photos d'une
 * personne », « les albums d'une photo », « les cercles d'un membre »…).
 */
return new class extends Migration
{
    private const INDEXES = [
        'media_person' => 'person_id',
        'album_media' => 'media_id',
        'household_user' => 'user_id',
        'life_event_media' => 'media_id',
        'album_access' => 'user_id',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $column) {
            if (Schema::hasTable($table) && ! Schema::hasIndex($table, [$column])) {
                Schema::table($table, fn ($t) => $t->index($column));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $column) {
            if (Schema::hasTable($table) && Schema::hasIndex($table, [$column])) {
                Schema::table($table, fn ($t) => $t->dropIndex([$column]));
            }
        }
    }
};
