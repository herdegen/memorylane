<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les deux « non » de la quête « Qui est-ce ? » masquaient le visage, même
 * « Personne inconnue / hors famille » : un invité disparaissait de la
 * photo. On rend visibles (rejected : hors quêtes, ré-identifiable à la
 * main) les visages écartés par une quête qu'InsightFace confirme comme
 * vrais visages (détection retrouvée, engine = insightface). Les fausses
 * détections restent masquées. Rejouable sans effet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE detected_faces f
            SET status = 'rejected'
            WHERE f.status = 'dismissed'
              AND f.person_id IS NULL
              AND f.engine = 'insightface'
              AND EXISTS (
                  SELECT 1 FROM quest_answers q
                  WHERE q.question_type = 'face_identify' AND q.answer_kind = 'no' AND q.subject_id = f.id
              )
        SQL);

        \App\Services\Vision\FaceMatcher::bumpReferences();
    }

    public function down(): void
    {
        // Rien : le visage reste visible.
    }
};
