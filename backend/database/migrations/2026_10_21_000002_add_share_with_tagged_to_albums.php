<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Partager tout l'album avec les personnes identifiées dedans » : réglage
 * permanent, il vaut aussi pour les personnes identifiées plus tard.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->boolean('share_with_tagged')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('share_with_tagged');
        });
    }
};
