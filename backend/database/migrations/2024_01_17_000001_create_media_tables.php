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
        // Media table
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id'); // Uploader
            $table->enum('type', ['photo', 'video', 'document'])->index();
            $table->string('original_name');
            $table->string('file_path'); // S3 key
            $table->string('mime_type');
            $table->unsignedBigInteger('size'); // bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable(); // for videos, seconds
            $table->timestamp('uploaded_at')->useCurrent();
            $table->timestamp('taken_at')->nullable(); // from EXIF or manual
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->index('user_id');
            $table->index('taken_at');
            $table->index('created_at');
        });

        // Media metadata table
        Schema::create('media_metadata', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_id')->unique();
            $table->json('exif_data')->nullable();
            $table->string('camera_make')->nullable();
            $table->string('camera_model')->nullable();
            $table->unsignedInteger('iso')->nullable();
            $table->decimal('aperture', 4, 2)->nullable();
            $table->string('shutter_speed')->nullable();
            $table->unsignedInteger('focal_length')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('altitude', 8, 2)->nullable();
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
            $table->index(['latitude', 'longitude']);
        });

        // Media conversions table (thumbnails, optimized versions)
        Schema::create('media_conversions', function (Blueprint $table) {
            $table->id();
            $table->uuid('media_id');
            $table->string('conversion_name'); // thumbnail, medium, large, etc.
            $table->string('file_path'); // S3 key
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('mime_type')->nullable();
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
            $table->index('media_id');
            $table->unique(['media_id', 'conversion_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_conversions');
        Schema::dropIfExists('media_metadata');
        Schema::dropIfExists('media');
    }
};
