<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foyer d'accueil : celui que rejoint automatiquement toute personne
        // invitée (au plus un, garanti côté application).
        Schema::table('households', function (Blueprint $table) {
            $table->boolean('is_default')->default(false)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('is_default');
        });
    }
};
