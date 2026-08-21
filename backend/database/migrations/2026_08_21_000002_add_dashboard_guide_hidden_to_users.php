<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le bloc « Bien démarrer » de l'accueil est masquable PAR COMPTE :
 * l'horodatage du masquage vit côté serveur (pas en localStorage, pour
 * suivre l'utilisateur d'un appareil à l'autre).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('dashboard_guide_hidden_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_guide_hidden_at');
        });
    }
};
