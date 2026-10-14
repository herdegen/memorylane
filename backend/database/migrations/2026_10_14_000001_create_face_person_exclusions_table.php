<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Ce n'est pas X » : exclusions visage ↔ personne. Le moteur ne propose
 * plus jamais X pour ce visage (suggestions, quêtes, association
 * automatique, « Identifier par personne ») ; n'entre pas dans les
 * références de reconnaissance. Levée si le visage est identifié comme X.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_person_exclusions', function (Blueprint $table) {
            $table->uuid('detected_face_id');
            $table->uuid('person_id');
            $table->uuid('user_id')->nullable();
            $table->timestamps();

            $table->primary(['detected_face_id', 'person_id']);
            $table->index('person_id');
            $table->foreign('detected_face_id')->references('id')->on('detected_faces')->cascadeOnDelete();
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_person_exclusions');
    }
};
