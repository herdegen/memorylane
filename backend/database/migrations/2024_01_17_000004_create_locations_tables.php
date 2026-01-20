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
        // Locations table (hierarchical)
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('type', ['country', 'city', 'address', 'custom'])->default('custom');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->unsignedInteger('radius')->default(1000); // meters, for area matching
            $table->unsignedBigInteger('parent_id')->nullable(); // hierarchical structure
            $table->timestamps();

            $table->foreign('parent_id')->references('id')->on('locations')->onDelete('cascade');
            $table->index(['latitude', 'longitude']);
            $table->index('parent_id');
        });

        // Media locations table (manual override or association)
        Schema::create('media_locations', function (Blueprint $table) {
            $table->uuid('media_id')->unique();
            $table->unsignedBigInteger('location_id');
            $table->decimal('latitude', 10, 8)->nullable(); // manual override
            $table->decimal('longitude', 11, 8)->nullable(); // manual override
            $table->boolean('is_manual')->default(false);
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
            $table->foreign('location_id')->references('id')->on('locations')->onDelete('cascade');
            $table->index('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_locations');
        Schema::dropIfExists('locations');
    }
};
