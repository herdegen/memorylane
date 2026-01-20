<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Albums table
        Schema::create('albums', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id'); // Creator
            $table->string('name');
            $table->text('description')->nullable();
            $table->uuid('cover_media_id')->nullable();
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('cover_media_id')->references('id')->on('media')->onDelete('set null');
            $table->index('user_id');
        });

        // Album-Media pivot table
        Schema::create('album_media', function (Blueprint $table) {
            $table->uuid('album_id');
            $table->uuid('media_id');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->foreign('album_id')->references('id')->on('albums')->onDelete('cascade');
            $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
            $table->primary(['album_id', 'media_id']);
            $table->index('order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('album_media');
        Schema::dropIfExists('albums');
    }
};
