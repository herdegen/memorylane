<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « Ajouter à la fiche » : un média publié sur la fiche d'une personne est
 * visible de tous ceux qui voient cette fiche. fiche_status : null (simple
 * identification, sans effet de visibilité), pending (la personne a un
 * compte et doit valider), published, private (refusé ou retiré).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_person', function (Blueprint $table) {
            $table->string('fiche_status', 16)->nullable();
            $table->uuid('fiche_added_by')->nullable();
            $table->timestamp('fiche_decided_at')->nullable();
            $table->index(['person_id', 'fiche_status']);
            $table->foreign('fiche_added_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('media_person', function (Blueprint $table) {
            $table->dropForeign(['fiche_added_by']);
            $table->dropIndex(['person_id', 'fiche_status']);
            $table->dropColumn(['fiche_status', 'fiche_added_by', 'fiche_decided_at']);
        });
    }
};
