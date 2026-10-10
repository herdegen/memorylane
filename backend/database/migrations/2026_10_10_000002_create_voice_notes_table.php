<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mémos vocaux : un proche raconte une photo. Le fichier audio vit sur le
 * disque par défaut (Scaleway) ; `person_id` = la fiche du narrateur au
 * moment de l'enregistrement (survit à la suppression du compte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voice_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_id');
            $table->uuid('user_id')->nullable();
            $table->uuid('person_id')->nullable();
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->nullOnDelete();
            $table->index(['media_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voice_notes');
    }
};
