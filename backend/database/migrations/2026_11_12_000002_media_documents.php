<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Photos qui sont des documents (lettre, acte, journal) : rangées dans
 * « Documents », texte lu automatiquement ou saisi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->boolean('is_document')->default(false)->index();
            // ocr = classé par la lecture automatique, manual = par quelqu'un (on n'y revient plus)
            $table->string('document_source', 16)->nullable();
            // ocr = texte lu automatiquement, à relire ; manual = saisi
            $table->string('transcription_source', 16)->nullable();
            $table->timestamp('text_checked_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['is_document', 'document_source', 'transcription_source', 'text_checked_at']);
        });
    }
};
