<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('name');
            $table->string('slug')->unique();
            $table->date('birth_date')->nullable();
            $table->date('death_date')->nullable();
            $table->uuid('avatar_media_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('avatar_media_id')->references('id')->on('media')->onDelete('set null');
            $table->index('user_id');
            $table->index('name');
        });

        Schema::create('media_person', function (Blueprint $table) {
            $table->uuid('media_id');
            $table->uuid('person_id');
            $table->json('face_coordinates')->nullable();
            $table->timestamps();

            $table->primary(['media_id', 'person_id']);
            $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
            $table->foreign('person_id')->references('id')->on('people')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_person');
        Schema::dropIfExists('people');
    }
};
