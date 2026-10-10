<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode grand-parent : interface simplifiée (/simple) et accès tablette par
 * lien sans mot de passe. Seul le hash SHA-256 du jeton est stocké ; le
 * régénérer (tablet:create --revoke) invalide l'ancien lien.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('simple_mode')->default(false)->after('guest_person_id');
            $table->string('tablet_token_hash', 64)->nullable()->unique()->after('simple_mode');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tablet_token_hash']);
            $table->dropColumn(['simple_mode', 'tablet_token_hash']);
        });
    }
};
