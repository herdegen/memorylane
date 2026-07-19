<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Empreinte perceptuelle dHash (64 bits → 16 caractères hexa), pour
            // repérer les QUASI-doublons (rafales, recadrage, recompression) par
            // distance de Hamming. Distincte de content_hash (sha256, doublon
            // exact). Nullable : rétro-remplie par
            // `php artisan media:backfill-perceptual-hashes`.
            $table->string('perceptual_hash', 16)->nullable()->after('content_hash');
            // Index de scoping (le clustering se fait par utilisateur) ; la
            // distance de Hamming elle-même se calcule en mémoire.
            $table->index(['user_id', 'perceptual_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'perceptual_hash']);
            $table->dropColumn('perceptual_hash');
        });
    }
};
