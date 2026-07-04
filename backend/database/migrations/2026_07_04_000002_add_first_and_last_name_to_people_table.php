<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->index('last_name');
        });

        // Backfill best-effort depuis name : dernier mot = nom de famille,
        // le reste = prénom(s). Un seul mot = prénom seul.
        DB::table('people')->select('id', 'name')->orderBy('id')->chunkById(200, function ($people) {
            foreach ($people as $person) {
                $parts = preg_split('/\s+/', trim($person->name ?? ''));
                if (count($parts) > 1) {
                    $lastName = array_pop($parts);
                    $firstName = implode(' ', $parts);
                } else {
                    $firstName = $parts[0] ?? null;
                    $lastName = null;
                }

                DB::table('people')->where('id', $person->id)->update([
                    'first_name' => $firstName ?: null,
                    'last_name' => $lastName,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('people', function (Blueprint $table) {
            $table->dropIndex(['last_name']);
            $table->dropColumn(['first_name', 'last_name']);
        });
    }
};
