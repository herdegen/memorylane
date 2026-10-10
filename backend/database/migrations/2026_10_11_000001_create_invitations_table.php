<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invitation par e-mail à créer son accès, reliée à une fiche de l'arbre.
        // Le jeton n'est retrouvé que par son hash ; une copie chiffrée permet à
        // l'admin de recopier le lien (WhatsApp…) sans le régénérer.
        Schema::create('invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->uuid('person_id');
            $table->uuid('household_id')->nullable();
            $table->uuid('invited_by')->nullable();
            $table->uuid('user_id')->nullable(); // compte créé à l'acceptation
            $table->string('token_hash', 64)->nullable()->unique();
            $table->text('token')->nullable();
            $table->boolean('formal')->default(false); // vouvoiement
            $table->text('message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->foreign('person_id')->references('id')->on('people')->onDelete('cascade');
            $table->foreign('household_id')->references('id')->on('households')->onDelete('set null');
            $table->foreign('invited_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
