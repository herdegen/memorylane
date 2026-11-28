<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos retouchées : la version retouchée devient l'image de la photo, le
 * scan d'origine est gardé à côté (fichier + aperçu) pour pouvoir basculer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('original_scan_path')->nullable();
            $table->string('original_scan_preview_path')->nullable();
            $table->timestamp('retouched_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['original_scan_path', 'original_scan_preview_path', 'retouched_at']);
        });
    }
};
