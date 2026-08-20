<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Partage média ↔ foyer (many-to-many). Miroir de household_user :
        // un média partagé dans un foyer devient lisible par tous ses membres
        // (branche foyer de Media::scopeAccessibleBy).
        Schema::create('household_media', function (Blueprint $table) {
            $table->uuid('household_id');
            $table->uuid('media_id');
            $table->uuid('added_by')->nullable(); // traçabilité
            $table->timestamps();

            // Pivot standard (pas de clé `id`) : clé primaire composite pour que
            // attach()/detach() fonctionnent sans id auto-généré.
            $table->primary(['household_id', 'media_id']);
            $table->index('media_id');

            $table->foreign('household_id')->references('id')->on('households')->onDelete('cascade');
            $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
            $table->foreign('added_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_media');
    }
};
