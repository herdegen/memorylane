<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Précision de la date d'un moment de vie : beaucoup de souvenirs ne sont
 * connus qu'à l'année (« en 1950 ») ou au mois. `event_date` reste une vraie
 * date (tri de la frise), `date_precision` dit seulement ce qu'on en affiche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('life_events', function (Blueprint $table) {
            $table->string('date_precision', 5)->default('day')->after('end_date'); // day|month|year
        });
    }

    public function down(): void
    {
        Schema::table('life_events', function (Blueprint $table) {
            $table->dropColumn('date_precision');
        });
    }
};
