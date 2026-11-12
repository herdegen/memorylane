<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fermeture d'une famille (30 jours pour qu'un membre la reprenne) et fiches
 * archivées : une fiche qui n'appartient plus à aucune famille est gardée
 * pour la généalogie, invisible de tous, sans ses photos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->timestamp('closure_requested_at')->nullable();
            $table->uuid('closure_requested_by')->nullable();
            $table->foreign('closure_requested_by')->references('id')->on('users')->nullOnDelete();
        });
        Schema::table('people', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('people', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('households', function (Blueprint $table) {
            $table->dropForeign(['closure_requested_by']);
            $table->dropColumn(['closure_requested_at', 'closure_requested_by']);
        });
    }
};
