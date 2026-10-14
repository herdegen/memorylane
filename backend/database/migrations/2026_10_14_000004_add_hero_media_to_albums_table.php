<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fond du titre d'un album, choisi parmi ses médias : une photo, ou une
 * vidéo (extrait muet en boucle, conversion « hero », cf. HeroClipService).
 * Sans choix : la carte si l'album est géolocalisé, sinon la couverture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->uuid('hero_media_id')->nullable()->after('cover_media_id');
            $table->foreign('hero_media_id')->references('id')->on('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('albums', function (Blueprint $table) {
            $table->dropForeign(['hero_media_id']);
            $table->dropColumn('hero_media_id');
        });
    }
};
