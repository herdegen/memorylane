<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moments enrichis : lien vers un album (en plus de la photo) et lieu
 * géolocalisé (lat/lng en plus du libellé `place`) — prépare l'animation
 * carte du diaporama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('life_events', function (Blueprint $table) {
            $table->decimal('latitude', 10, 8)->nullable()->after('place');
            $table->decimal('longitude', 11, 8)->nullable()->after('latitude');
            $table->uuid('album_id')->nullable()->after('media_id');

            $table->foreign('album_id')->references('id')->on('albums')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('life_events', function (Blueprint $table) {
            $table->dropForeign(['album_id']);
            $table->dropColumn(['latitude', 'longitude', 'album_id']);
        });
    }
};
