<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * « Public » ne veut plus dire « tous les comptes » mais « mes cercles » :
     * chaque album public est partagé avec les cercles familiaux de son
     * propriétaire (à défaut, tous ses cercles), puis repasse en non public.
     * Un propriétaire sans cercle garde l'album tel quel (devenu privé de fait).
     */
    public function up(): void
    {
        $albums = DB::table('albums')->where('is_public', true)->get(['id', 'user_id']);

        foreach ($albums as $album) {
            $circles = DB::table('household_user')
                ->join('households', 'households.id', '=', 'household_user.household_id')
                ->where('household_user.user_id', $album->user_id)
                ->where('households.kind', 'family')
                ->pluck('households.id');

            if ($circles->isEmpty()) {
                $circles = DB::table('household_user')->where('user_id', $album->user_id)->pluck('household_id');
            }

            foreach ($circles as $householdId) {
                DB::table('album_household')->insertOrIgnore([
                    'album_id' => $album->id,
                    'household_id' => $householdId,
                    'granted_by' => $album->user_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            if ($circles->isNotEmpty()) {
                DB::table('albums')->where('id', $album->id)->update(['is_public' => false]);
            }
        }
    }

    public function down(): void
    {
        // Données : pas de retour arrière automatique (les partages créés
        // restent ; ils se retirent depuis l'album).
    }
};
