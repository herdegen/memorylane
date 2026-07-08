<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accès accordés à un album non-public (partage à des comptes choisis,
        // délégation récursive). L'accès « public » reste porté par albums.is_public,
        // et l'accès « tagué » est dérivé de media_person — cf. Album::isAccessibleBy.
        Schema::create('album_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('album_id');
            $table->uuid('user_id');
            $table->uuid('granted_by')->nullable(); // qui a accordé (traçabilité / révocation)
            $table->timestamps();

            $table->unique(['album_id', 'user_id']);
            $table->index(['album_id', 'user_id']);

            $table->foreign('album_id')->references('id')->on('albums')->onDelete('cascade');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('granted_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_access');
    }
};
