<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Partage « vivant » d'un média ou d'un album avec une branche de l'arbre :
// tous les descendants de la personne choisie (et leurs conjoints si
// include_spouses), calculés à chaque accès — un petit-enfant qui crée son
// compte plus tard, un conjoint ajouté à l'arbre y ont accès d'office.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_shares', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('shareable_type', 16); // media | album
            $table->uuid('shareable_id');
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->boolean('include_spouses')->default(true);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['shareable_type', 'shareable_id', 'person_id']);
            $table->index('person_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_shares');
    }
};
