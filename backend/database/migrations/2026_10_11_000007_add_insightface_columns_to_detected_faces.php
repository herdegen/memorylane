<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moteur InsightFace : nouvelles colonnes À CÔTÉ des anciennes (l'empreinte
 * face-api reste dans `embedding`, rien n'est écrasé ni supprimé).
 * - embedding_v2 : empreinte ArcFace 512, normée.
 * - age_estimate : âge apparent estimé (signal faible).
 * - engine : moteur ayant produit embedding_v2 (insightface, insightface-box).
 * - verified_at : identification confirmée par un humain (panneau, quête,
 *   revue) — jamais posée par l'association automatique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('detected_faces', function (Blueprint $table) {
            $table->json('embedding_v2')->nullable();
            $table->unsignedSmallInteger('age_estimate')->nullable();
            $table->string('engine', 32)->nullable();
            $table->timestamp('verified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('detected_faces', function (Blueprint $table) {
            $table->dropColumn(['embedding_v2', 'age_estimate', 'engine', 'verified_at']);
        });
    }
};
