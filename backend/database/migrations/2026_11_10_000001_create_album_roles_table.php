<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôles des personnes dans un album à thème (marié·e, témoin, baptisé·e,
 * parrain, marraine, fêté·e) : la page de l'album les met en avant.
 * Table à part de album_participants : déclarer un participant trie les
 * visages inconnus de l'album, un rôle ne doit rien trier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('album_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('album_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->string('role', 20);
            $table->foreignUuid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['album_id', 'person_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('album_roles');
    }
};
