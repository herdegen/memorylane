<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detected_faces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_id');
            $table->uuid('person_id')->nullable();
            $table->json('bounding_box');
            $table->float('confidence')->nullable();
            $table->json('landmarks')->nullable();
            $table->float('joy_likelihood')->nullable();
            $table->float('sorrow_likelihood')->nullable();
            $table->float('anger_likelihood')->nullable();
            $table->float('surprise_likelihood')->nullable();
            $table->float('roll_angle')->nullable();
            $table->float('pan_angle')->nullable();
            $table->float('tilt_angle')->nullable();
            $table->string('provider')->default('google');
            $table->string('status')->default('unmatched');
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('media')->onDelete('cascade');
            $table->foreign('person_id')->references('id')->on('people')->onDelete('set null');
            $table->index('media_id');
            $table->index('person_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detected_faces');
    }
};
