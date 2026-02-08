<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media_metadata', function (Blueprint $table) {
            $table->json('vision_labels')->nullable()->after('altitude');
            $table->string('vision_status')->nullable()->after('vision_labels');
            $table->string('vision_provider')->nullable()->after('vision_status');
            $table->timestamp('vision_processed_at')->nullable()->after('vision_provider');
            $table->text('vision_error')->nullable()->after('vision_processed_at');
            $table->integer('vision_faces_count')->default(0)->after('vision_error');
        });
    }

    public function down(): void
    {
        Schema::table('media_metadata', function (Blueprint $table) {
            $table->dropColumn([
                'vision_labels',
                'vision_status',
                'vision_provider',
                'vision_processed_at',
                'vision_error',
                'vision_faces_count',
            ]);
        });
    }
};
