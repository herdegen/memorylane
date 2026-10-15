<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Image choisie pour représenter une vidéo (miniature, recherche de
 * visages), en secondes. Sans choix : l'image à 10 % de la durée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->float('thumbnail_at')->nullable()->after('clip_end');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('thumbnail_at');
        });
    }
};
