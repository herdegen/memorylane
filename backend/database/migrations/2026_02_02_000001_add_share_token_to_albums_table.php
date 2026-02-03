<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('is_public');
            $table->string('slug')->nullable()->after('name');
            $table->softDeletes();

            $table->index('share_token');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropIndex(['share_token']);
            $table->dropIndex(['slug']);
            $table->dropColumn(['share_token', 'slug', 'deleted_at']);
        });
    }
};
