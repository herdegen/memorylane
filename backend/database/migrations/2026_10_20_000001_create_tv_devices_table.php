<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Télévisions reliées à un compte (interface /tv/ecran, télécommande).
 * Une ligne sans user_id = code d'appairage affiché sur une TV, en attente
 * de validation depuis le téléphone. Une fois validée et récupérée par la
 * TV, le code disparaît et seul l'empreinte du jeton de l'appareil reste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tv_devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Données techniques : partent avec le compte.
            $table->foreignUuid('user_id')->nullable()->constrained()->cascadeOnDelete();
            // Cercle affiché sur cette TV (indépendant du cercle courant du téléphone).
            $table->foreignUuid('household_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60)->default('Télévision');
            $table->string('pairing_code', 6)->nullable()->index();
            $table->timestamp('pairing_expires_at')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tv_devices');
    }
};
