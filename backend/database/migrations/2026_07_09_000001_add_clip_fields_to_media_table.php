<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Découpage vidéo : un clip est un Media à part entière issu d'une vidéo source.
 *
 * - source_media_id : lien clip -> vidéo d'origine (null pour un média normal)
 * - clip_start / clip_end : bornes du clip dans la source (secondes)
 * - is_source : vrai sur une vidéo qui a été découpée -> masquée de la galerie
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->uuid('source_media_id')->nullable()->after('user_id');
            $table->float('clip_start')->nullable()->after('duration');
            $table->float('clip_end')->nullable()->after('clip_start');
            $table->boolean('is_source')->default(false)->after('clip_end');

            $table->foreign('source_media_id')->references('id')->on('media')->nullOnDelete();
            $table->index('source_media_id');
            $table->index('is_source');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign(['source_media_id']);
            $table->dropColumn(['source_media_id', 'clip_start', 'clip_end', 'is_source']);
        });
    }
};
