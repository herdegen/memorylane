<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detected_faces', function (Blueprint $table) {
            // Descripteur facial (128 floats) produit par face-api.js, stocké
            // dès la Phase 1 pour alimenter la reconnaissance (Phase 2).
            $table->json('embedding')->nullable()->after('landmarks');
        });
    }

    public function down(): void
    {
        Schema::table('detected_faces', function (Blueprint $table) {
            $table->dropColumn('embedding');
        });
    }
};
