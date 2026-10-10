<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Historique des modifications de l'arbre (fiches ouvertes en écriture
        // à tous les membres) : qui a changé quoi, pour pouvoir annuler.
        Schema::create('person_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id');
            $table->uuid('user_id')->nullable();
            // created | updated | deleted | restored | spouse_added | spouse_removed
            $table->string('action', 32);
            $table->json('diff')->nullable(); // {champ: [avant, après]} ou contexte
            $table->timestamp('reverted_at')->nullable();
            $table->uuid('reverted_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['person_id', 'created_at']);
            $table->index('created_at');
            // Pas de FK sur person_id : l'historique survit à la suppression
            // définitive d'une fiche.
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
            $table->foreign('reverted_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_changes');
    }
};
