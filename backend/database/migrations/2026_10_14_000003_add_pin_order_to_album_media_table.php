<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * « À la une » : les médias épinglés d'un album s'affichent en tête, dans
 * l'ordre choisi (pin_order), avant le reste trié par date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('album_media', function (Blueprint $table) {
            $table->unsignedInteger('pin_order')->nullable()->after('order');
        });
    }

    public function down(): void
    {
        Schema::table('album_media', function (Blueprint $table) {
            $table->dropColumn('pin_order');
        });
    }
};
