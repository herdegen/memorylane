<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Qui peut faire entrer quelqu'un dans le cercle (invitation par mail
        // ou ajout d'un compte) : tous les membres, ou l'admin seulement.
        Schema::table('households', function (Blueprint $table) {
            $table->string('invite_policy', 16)->default('everyone')->after('kind');
        });

        // Défaut par type : la famille invite librement, les amis passent
        // par l'admin.
        DB::table('households')->where('kind', 'friends')->update(['invite_policy' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('households', function (Blueprint $table) {
            $table->dropColumn('invite_policy');
        });
    }
};
