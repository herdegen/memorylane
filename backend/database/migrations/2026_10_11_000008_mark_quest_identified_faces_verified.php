<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les visages identifiés par un humain via une quête « Qui est-ce ? » (et
 * toujours associés à la personne répondue) sont marqués vérifiés : la revue
 * InsightFace ne les remet pas en cause. Rejouable sans effet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE detected_faces f
            SET verified_at = q.created_at
            FROM quest_answers q
            WHERE q.question_type = 'face_identify'
              AND q.answer_kind = 'answered'
              AND q.subject_id = f.id
              AND f.person_id::text = (q.payload::json ->> 'person_id')
              AND f.verified_at IS NULL
        SQL);

        // Mise à jour SQL directe : invalider les analyses en cache.
        \App\Services\Vision\FaceMatcher::bumpReferences();
    }

    public function down(): void
    {
        // Rien : les dates de vérification restent valables.
    }
};
