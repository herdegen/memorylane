<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histoires de famille : un récit en chapitres (texte, citations, documents)
 * qui concerne une ou plusieurs personnes, avec ses désaccords de sources et
 * ses questions ouvertes ; et la transcription du texte des documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            // documented = histoire vraie documentée, memory = souvenir, legend = légende familiale
            $table->string('kind', 16)->default('memory');
            $table->string('credits')->nullable();
            $table->foreignUuid('album_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('sensitive')->default(false);
            $table->text('disagreements')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('story_chapters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('story_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title');
            $table->string('when_label')->nullable();
            $table->json('blocks');
            $table->timestamps();
            $table->index(['story_id', 'position']);
        });

        Schema::create('story_person', function (Blueprint $table) {
            $table->foreignUuid('story_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('person_id')->constrained('people')->cascadeOnDelete();
            $table->boolean('is_main')->default(false);
            $table->string('role')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->primary(['story_id', 'person_id']);
            $table->index('person_id');
        });

        Schema::create('story_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('story_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('text', 500);
            $table->timestamps();
        });

        Schema::create('story_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('story_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('question_id')->nullable()->constrained('story_questions')->nullOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::table('media', function (Blueprint $table) {
            $table->text('transcription')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('media', fn (Blueprint $table) => $table->dropColumn('transcription'));
        Schema::dropIfExists('story_notes');
        Schema::dropIfExists('story_questions');
        Schema::dropIfExists('story_person');
        Schema::dropIfExists('story_chapters');
        Schema::dropIfExists('stories');
    }
};
