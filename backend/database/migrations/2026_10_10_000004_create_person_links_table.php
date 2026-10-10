<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // « Comment on s'est connus » : récit d'une rencontre entre deux
        // personnes, UNE version par auteur (chacun raconte la sienne).
        // Paire stockée triée (person1_id < person2_id).
        Schema::create('person_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person1_id');
            $table->uuid('person2_id');
            $table->uuid('created_by');
            $table->string('context', 20)->nullable();   // école, études, travail…
            $table->uuid('via_person_id')->nullable();   // « par Léa »
            $table->text('story')->nullable();
            $table->unsignedSmallInteger('met_year')->nullable();
            $table->timestamps();

            $table->unique(['person1_id', 'person2_id', 'created_by']);
            $table->index('person2_id');

            $table->foreign('person1_id')->references('id')->on('people')->onDelete('cascade');
            $table->foreign('person2_id')->references('id')->on('people')->onDelete('cascade');
            $table->foreign('created_by')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('via_person_id')->references('id')->on('people')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_links');
    }
};
