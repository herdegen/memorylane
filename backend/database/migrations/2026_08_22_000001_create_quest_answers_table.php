<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal des réponses aux « quêtes » (gamification de complétion des données).
 *
 * Chaque ligne est à la fois une trace d'audit (qui a répondu quoi, payload
 * saisi) et le mécanisme d'extinction des questions : une réponse « answered »
 * ou « non » éteint la question pour tout le monde, « je ne sais pas » et
 * « passer » la masquent pour ce seul utilisateur (avec expiration, cf.
 * QuestService). Pas de contrainte unique : la ré-éligibilité après TTL
 * produit légitimement plusieurs lignes pour une même question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quest_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('question_type', 40);
            // Clé canonique de la question : "{type}:{subject_id}".
            $table->string('question_key');
            $table->string('subject_type');
            $table->uuid('subject_id');
            $table->string('answer_kind', 12); // answered | no | dont_know | skipped
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['question_key', 'answer_kind', 'created_at']);
            $table->index(['user_id', 'question_key']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quest_answers');
    }
};
