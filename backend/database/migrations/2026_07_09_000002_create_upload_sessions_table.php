<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Suivi des uploads multipart directs vers S3 (gros fichiers / vidéos).
 *
 * Chaque session mémorise la clé S3 générée côté serveur et l'upload_id S3,
 * ce qui permet de valider la propriété à la finalisation, de recréer le Media
 * avec le bon nom/mime, et de nettoyer les uploads abandonnés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('upload_id', 1024);   // UploadId renvoyé par S3
            $table->string('s3_key');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('type'); // photo | video | document (déterminé côté serveur)
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_sessions');
    }
};
