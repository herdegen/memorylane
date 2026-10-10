<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos d'un moment de vie (plusieurs par moment, ordonnées) : la galerie
 * du moment sans passer par un album. `life_events.media_id` reste
 * l'illustration principale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('life_event_media', function (Blueprint $table) {
            $table->uuid('life_event_id');
            $table->uuid('media_id');
            $table->unsignedSmallInteger('order')->default(0);
            $table->timestamps();

            $table->primary(['life_event_id', 'media_id']);
            $table->foreign('life_event_id')->references('id')->on('life_events')->cascadeOnDelete();
            $table->foreign('media_id')->references('id')->on('media')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('life_event_media');
    }
};
