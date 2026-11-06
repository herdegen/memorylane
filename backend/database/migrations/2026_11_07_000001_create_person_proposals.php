<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fiches communes à plusieurs familles : les corrections d'une famille qui
 * n'est pas la référente de la fiche deviennent des propositions, et un
 * désaccord sur une modification la met de côté le temps d'en discuter.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person_id');
            $table->foreign('person_id')->references('id')->on('people')->cascadeOnDelete();
            // Famille et compte de qui propose (ou de l'auteur de la
            // modification contestée).
            $table->uuid('household_id')->nullable();
            $table->foreign('household_id')->references('id')->on('households')->nullOnDelete();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            // proposal : correction proposée ; dispute : modification
            // contestée (« Je ne suis pas d'accord »), remise à l'ancienne valeur.
            $table->string('origin', 16)->default('proposal');
            $table->json('changes');
            $table->text('source')->nullable();
            $table->uuid('person_change_id')->nullable();
            $table->uuid('disputed_by')->nullable();
            $table->foreign('disputed_by')->references('id')->on('users')->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->uuid('decided_by')->nullable();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'person_id']);
        });

        Schema::create('person_proposal_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('proposal_id');
            $table->foreign('proposal_id')->references('id')->on('person_proposals')->cascadeOnDelete();
            $table->uuid('user_id')->nullable();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        // Modifications appliquées par une proposition acceptée ou par un
        // désaccord : elles se lisent dans la proposition, pas comme des
        // nouveautés des autres familles.
        Schema::table('person_changes', function (Blueprint $table) {
            $table->uuid('proposal_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('person_changes', fn (Blueprint $table) => $table->dropColumn('proposal_id'));
        Schema::dropIfExists('person_proposal_messages');
        Schema::dropIfExists('person_proposals');
    }
};
