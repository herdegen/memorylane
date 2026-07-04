<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('video_codec')->nullable()->after('duration');
            $table->string('audio_codec')->nullable()->after('video_codec');
            $table->float('fps')->nullable()->after('audio_codec');
            $table->unsignedInteger('bitrate')->nullable()->after('fps'); // kbps
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['video_codec', 'audio_codec', 'fps', 'bitrate']);
        });
    }
};
