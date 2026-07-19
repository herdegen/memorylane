<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Appartenance compte ↔ foyer (many-to-many). Miroir de album_access.
        Schema::create('household_user', function (Blueprint $table) {
            $table->uuid('household_id');
            $table->uuid('user_id');
            $table->uuid('invited_by')->nullable(); // traçabilité
            $table->timestamps();

            // Pivot standard (pas de clé `id`) : clé primaire composite pour que
            // attach()/sync() fonctionnent sans id auto-généré.
            $table->primary(['household_id', 'user_id']);

            $table->foreign('household_id')->references('id')->on('households')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('invited_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_user');
    }
};
