<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Date d'un album : ses photos et vidéos sans date en héritent.
 * media.taken_at_album_id retient l'album dont vient la date (null = date
 * propre : appareil photo, saisie à la main, import), pour pouvoir la mettre
 * à jour ou la retirer sans jamais toucher une vraie date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->date('date')->nullable()->after('description');
        });

        Schema::table('media', function (Blueprint $table) {
            $table->uuid('taken_at_album_id')->nullable()->after('taken_at');
            $table->foreign('taken_at_album_id')->references('id')->on('albums')->nullOnDelete();
            $table->index('taken_at_album_id');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign(['taken_at_album_id']);
            $table->dropColumn('taken_at_album_id');
        });
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn('date');
        });
    }
};
