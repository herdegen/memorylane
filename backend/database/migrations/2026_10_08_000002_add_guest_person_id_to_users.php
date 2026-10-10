<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Fiche d'arrivée d'un invité temporaire : seuls ses médias (taggés
        // ou illustrant ses moments de vie) lui sont visibles.
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('guest_person_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('guest_person_id');
        });
    }
};
