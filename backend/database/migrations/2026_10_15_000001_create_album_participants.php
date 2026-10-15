<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // « Qui était du voyage ? » : les participants d'un album. Les
        // suggestions de visages de l'album se limitent à eux, et un visage
        // qui ne ressemble à aucun est rangé en « inconnu ».
        Schema::create('album_participants', function (Blueprint $table) {
            $table->uuid('album_id');
            $table->uuid('person_id');
            $table->uuid('added_by')->nullable();
            $table->timestamps();

            $table->primary(['album_id', 'person_id']);
            $table->index('person_id');

            $table->foreign('album_id')->references('id')->on('albums')->onDelete('cascade');
            $table->foreign('person_id')->references('id')->on('people')->onDelete('cascade');
            $table->foreign('added_by')->references('id')->on('users')->onDelete('set null');
        });

        // Visage rangé automatiquement en « inconnu » à cause des participants
        // d'un album : marqué pour pouvoir annuler (retour à « à identifier »).
        Schema::table('detected_faces', function (Blueprint $table) {
            $table->uuid('auto_rejected_album_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('detected_faces', function (Blueprint $table) {
            $table->dropIndex(['auto_rejected_album_id']);
            $table->dropColumn('auto_rejected_album_id');
        });
        Schema::dropIfExists('album_participants');
    }
};
