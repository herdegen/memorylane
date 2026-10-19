<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Départ de l'application : demande programmée (30 jours, annulable) et
 * choix faits (photos, souvenirs racontés, identifications, successeurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('departure_requested_at')->nullable();
            $table->json('departure_options')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['departure_requested_at', 'departure_options']);
        });
    }
};
