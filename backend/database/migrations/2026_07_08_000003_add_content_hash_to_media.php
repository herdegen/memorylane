<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // sha256 du contenu du fichier, pour une déduplication d'import
            // fiable (indépendante du nom). Nullable : les médias existants
            // sont rétro-remplis par `php artisan media:backfill-hashes`.
            $table->string('content_hash', 64)->nullable()->after('file_path');
            $table->index(['user_id', 'content_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'content_hash']);
            $table->dropColumn('content_hash');
        });
    }
};
