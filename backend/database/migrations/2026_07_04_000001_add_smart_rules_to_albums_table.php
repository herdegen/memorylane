<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->boolean('is_smart')->default(false)->after('is_public');
            // Règles combinées (ET) : person_id, tag_id, year, type
            $table->jsonb('smart_rules')->nullable()->after('is_smart');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropColumn(['is_smart', 'smart_rules']);
        });
    }
};
