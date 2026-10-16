<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cercle courant au moment de la réponse : « personne inconnue / pas du
 * groupe » sur un visage ne vaut que pour ce cercle (une autre famille ou
 * un autre groupe d'amis peut la connaître).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quest_answers', function (Blueprint $table) {
            $table->uuid('household_id')->nullable()->after('user_id');
            $table->index(['household_id', 'question_key']);
        });
    }

    public function down(): void
    {
        Schema::table('quest_answers', function (Blueprint $table) {
            $table->dropIndex(['household_id', 'question_key']);
            $table->dropColumn('household_id');
        });
    }
};
