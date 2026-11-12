<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Récit de vie guidé : la réponse d'un compte à une étape de la vie d'une
 * personne (la sienne ou celle d'un proche) — récit écrit ou à l'oral, et
 * les moments de la frise créés à cette étape (pour les modifier sans
 * doublon en y revenant).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('life_story_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id');
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->string('chapter', 32);
            // done : validée ; skipped : passée.
            $table->string('status', 16)->nullable();
            $table->text('body')->nullable();
            $table->json('event_ids')->nullable();
            $table->string('audio_path')->nullable();
            $table->unsignedInteger('audio_duration')->nullable();
            $table->text('transcript')->nullable();
            // Relu et exploité (détails ajoutés à l'arbre).
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();
            $table->unique(['person_id', 'user_id', 'chapter']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_story_answers');
    }
};
