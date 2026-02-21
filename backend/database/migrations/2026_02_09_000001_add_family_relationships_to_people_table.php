<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->uuid('father_id')->nullable()->after('notes');
            $table->uuid('mother_id')->nullable()->after('father_id');
            $table->enum('gender', ['M', 'F', 'U'])->default('U')->after('mother_id');
            $table->string('maiden_name')->nullable()->after('name');
            $table->string('birth_place')->nullable()->after('birth_date');
            $table->string('death_place')->nullable()->after('death_date');
            $table->string('gedcom_id')->nullable()->after('death_place');

            $table->foreign('father_id')->references('id')->on('people')->onDelete('set null');
            $table->foreign('mother_id')->references('id')->on('people')->onDelete('set null');
            $table->index('father_id');
            $table->index('mother_id');
            $table->index('gedcom_id');
        });

        Schema::create('person_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('person1_id');
            $table->uuid('person2_id');
            $table->enum('type', ['spouse', 'partner'])->default('spouse');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('start_place')->nullable();
            $table->timestamps();

            $table->foreign('person1_id')->references('id')->on('people')->onDelete('cascade');
            $table->foreign('person2_id')->references('id')->on('people')->onDelete('cascade');
            $table->unique(['person1_id', 'person2_id', 'type']);
            $table->index('person2_id');
        });

        Schema::create('gedcom_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('filename');
            $table->enum('status', ['pending', 'matching', 'importing', 'completed', 'failed'])->default('pending');
            $table->json('parsed_data')->nullable();
            $table->json('matching_decisions')->nullable();
            $table->integer('individuals_count')->default(0);
            $table->integer('families_count')->default(0);
            $table->integer('imported_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gedcom_imports');
        Schema::dropIfExists('person_relationships');

        Schema::table('people', function (Blueprint $table) {
            $table->dropForeign(['father_id']);
            $table->dropForeign(['mother_id']);
            $table->dropColumn([
                'father_id', 'mother_id', 'gender',
                'maiden_name', 'birth_place', 'death_place', 'gedcom_id',
            ]);
        });
    }
};
