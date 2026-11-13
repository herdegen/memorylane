<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Vidéo en cours de conversion (MP4 optimisé d'un vieux format, version web) :
// la galerie, la fiche et la page d'envoi l'indiquent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->timestamp('converting_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media', fn (Blueprint $table) => $table->dropColumn('converting_since'));
    }
};
