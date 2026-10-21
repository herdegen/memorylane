<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des newsletters envoyées : quelles rubriques, quelle personne
 * de la semaine, quelle fonctionnalité mise en avant — pour varier d'une
 * semaine à l'autre et ne jamais resservir la même chose trop tôt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_sends', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('edition', 16)->default('family');
            $table->json('sections');
            $table->string('subject');
            $table->timestamp('sent_at');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_sends');
    }
};
