<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portrait stocké de chaque fiche (480×640, cadré sur le visage) : un petit
 * fichier indépendant de la photo d'origine, servi directement et mis en
 * cache par le navigateur. `portrait_source` = empreinte de ce qui l'a
 * produit (photo + visage) : on ne le refait que si elle change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('portrait_path')->nullable();
            $table->string('portrait_source', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['portrait_path', 'portrait_source']);
        });
    }
};
