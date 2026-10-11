<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nettoyage (lot 4) : structures jamais utilisées par l'application.
 * - users.pin_code : vestige d'un code PIN jamais implémenté (aucune valeur
 *   en base) ; un PIN se stockerait haché, pas en clair.
 * - locations / media_locations : ancien modèle de lieux, remplacé par les
 *   coordonnées de media_metadata ; tables vides.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'pin_code')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('pin_code'));
        }
        Schema::dropIfExists('media_locations');
        Schema::dropIfExists('locations');
    }

    public function down(): void
    {
        // Pas de retour : structures mortes, jamais remplies.
    }
};
