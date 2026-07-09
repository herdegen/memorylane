<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moments de vie rattachés à une personne (frise chronologique).
 *
 * Les événements « famille » (naissance, mariage, naissances des enfants,
 * décès) restent déduits des données existantes ; cette table ne stocke que
 * les moments LIBRES ajoutés à la main (emploi, diplôme, résidence, etc.),
 * éventuellement illustrés par une photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('life_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id');
            $table->uuid('user_id'); // créateur
            $table->string('type')->default('moment'); // moment|job|education|residence|custom
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('place')->nullable();
            $table->date('event_date');
            $table->date('end_date')->nullable(); // périodes (ex. emploi)
            $table->uuid('media_id')->nullable(); // photo illustrant le moment
            $table->timestamps();

            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('media_id')->references('id')->on('media')->nullOnDelete();
            $table->index(['person_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_events');
    }
};
