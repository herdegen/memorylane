<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Édition partagée : tout membre qui voit une photo peut en corriger la date,
 * le lieu et les personnes ; chaque correction est journalisée (media_changes)
 * et annulable par l'auteur de la photo, l'admin ou la personne qui l'a faite.
 * Une union peut aussi porter sa photo (carte « Mariage » de la frise).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_id');
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // updated, geolocated, geolocation_removed, face_identified,
            // face_reset, face_dismissed, face_added
            $table->string('action', 32);
            $table->json('diff');
            $table->timestamp('reverted_at')->nullable();
            $table->uuid('reverted_by')->nullable();
            $table->foreign('reverted_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['media_id', 'created_at']);
        });

        Schema::table('person_relationships', function (Blueprint $table) {
            $table->uuid('media_id')->nullable()->after('start_place');
            $table->foreign('media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('person_relationships', function (Blueprint $table) {
            $table->dropForeign(['media_id']);
            $table->dropColumn('media_id');
        });
        Schema::dropIfExists('media_changes');
    }
};
