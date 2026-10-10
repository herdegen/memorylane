<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cercles : un foyer est désormais de type « famille » ou « amis ».
        // Les foyers existants restent des cercles familiaux.
        Schema::table('households', function (Blueprint $table) {
            $table->string('kind', 16)->default('family')->after('name');
        });

        // Cercle courant choisi dans le sélecteur de l'en-tête.
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('current_household_id')->nullable()->after('person_id');
            $table->foreign('current_household_id')->references('id')->on('households')->nullOnDelete();
        });

        // Répertoire familial (arbre + liste ouverts à la famille) : vrai par
        // défaut, faux pour une fiche créée dans un cercle d'amis.
        Schema::table('people', function (Blueprint $table) {
            $table->boolean('in_family_directory')->default(true)->after('user_id');
        });

        // Fiches rattachées à un cercle d'amis : visibles de ses seuls membres.
        Schema::create('household_person', function (Blueprint $table) {
            $table->uuid('household_id');
            $table->uuid('person_id');
            $table->uuid('added_by')->nullable();
            $table->timestamps();

            $table->primary(['household_id', 'person_id']);
            $table->index('person_id');

            $table->foreign('household_id')->references('id')->on('households')->onDelete('cascade');
            $table->foreign('person_id')->references('id')->on('people')->onDelete('cascade');
            $table->foreign('added_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('household_person');

        Schema::table('people', function (Blueprint $table) {
            $table->dropColumn('in_family_directory');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_household_id']);
            $table->dropColumn('current_household_id');
        });

        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
