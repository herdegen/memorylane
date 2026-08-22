<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quasi-doublons (issue #42, tranche 3) : identifiant de groupe de similarité.
 *
 * Rempli par le clustering perceptuel (media:cluster-similar — union-find sur
 * la distance de Hamming des dHash, scopé par propriétaire). Nullable : null =
 * la photo n'appartient à aucun groupe de quasi-doublons. La valeur est le
 * plus petit id (UUID) du groupe → stable d'un recalcul à l'autre tant que la
 * composition du groupe ne change pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('similar_group_id', 36)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('similar_group_id');
        });
    }
};
