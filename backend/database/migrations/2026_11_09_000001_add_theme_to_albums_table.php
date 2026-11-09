<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thème d'album (liste fermée, cf. Album::THEMES) : filtre de la page Albums,
 * rangement (les archives sortent de la frise) et, ensuite, mise en scène de
 * l'album selon son thème. Null = « Moments », l'affichage par défaut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('theme', 20)->nullable()->after('date');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
