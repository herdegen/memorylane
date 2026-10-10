<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Album partagé avec un cercle entier : tous ses membres (présents et
        // futurs) y ont accès (branche cercle d'Album::scopeAccessibleBy).
        Schema::create('album_household', function (Blueprint $table) {
            $table->uuid('album_id');
            $table->uuid('household_id');
            $table->uuid('granted_by')->nullable();
            $table->timestamps();

            $table->primary(['album_id', 'household_id']);
            $table->index('household_id');

            $table->foreign('album_id')->references('id')->on('albums')->onDelete('cascade');
            $table->foreign('household_id')->references('id')->on('households')->onDelete('cascade');
            $table->foreign('granted_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_household');
    }
};
