<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adresse de résidence des personnes : texte libre saisi + géocodage BAN
 * (ville, lat/lng précises). Les coordonnées précises ne sont jamais
 * exposées au front : la carte n'en voit qu'une version arrondie (~1 km)
 * via la couche heatmap, et l'adresse texte n'est visible sur la fiche
 * que du propriétaire/admin et du foyer (si opt-in).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('address')->nullable()->after('death_place');
            $table->string('address_city')->nullable()->after('address');
            $table->decimal('address_latitude', 10, 8)->nullable()->after('address_city');
            $table->decimal('address_longitude', 11, 8)->nullable()->after('address_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn(['address', 'address_city', 'address_latitude', 'address_longitude']);
        });
    }
};
