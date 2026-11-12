<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Récaps déjà regardés (pastille « nouveau » sur l'Accueil). Les récaps
 * eux-mêmes sont calculés à la demande, pour chaque visiteur (seulement les
 * photos qu'il peut voir) : rien d'autre à stocker.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recap_views', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('recap_key', 100);
            $table->timestamp('seen_at');
            $table->unique(['user_id', 'recap_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recap_views');
    }
};
