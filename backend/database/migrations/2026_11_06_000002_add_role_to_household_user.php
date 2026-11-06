<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Responsables de cercle (famille comme amis) : invitent, retirent, règlent,
 * partagent, et désignent d'autres responsables. Le créateur d'un cercle en
 * est responsable ; un cercle sans responsable parmi ses membres (créé par un
 * administrateur qui n'en fait pas partie) : ses membres administrateurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('household_user', function (Blueprint $table) {
            $table->string('role', 16)->default('member')->after('invited_by');
        });

        DB::statement(<<<'SQL'
            UPDATE household_user hu SET role = 'manager'
            FROM households h
            WHERE h.id = hu.household_id AND hu.user_id = h.created_by
        SQL);
        DB::statement(<<<'SQL'
            UPDATE household_user hu SET role = 'manager'
            FROM users u
            WHERE u.id = hu.user_id AND u.role = 'admin'
              AND NOT EXISTS (SELECT 1 FROM household_user x WHERE x.household_id = hu.household_id AND x.role = 'manager')
        SQL);
    }

    public function down(): void
    {
        Schema::table('household_user', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
