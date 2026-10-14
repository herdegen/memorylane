<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vidéo en fond du titre d'un album : un extrait muet en boucle (conversion
 * « hero » de la vidéo, cf. HeroClipService) remplace la carte ou la photo
 * de couverture dans l'en-tête.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->uuid('hero_video_id')->nullable()->after('cover_media_id');
            $table->foreign('hero_video_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropForeign(['hero_video_id']);
            $table->dropColumn('hero_video_id');
        });
    }
};
