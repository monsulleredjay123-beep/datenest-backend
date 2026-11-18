<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gewang na ang 'public_place' nga account. Ang admin na ang mo-add sa public places.
 * Kung naa nay daan nga public_place account: ilisan ang owner sa iyang mga lugar ngadto sa admin,
 * dayon i-delete ang account (kinahanglan ilisan una ang owner, kay kung dili ma-delete pud ang mga lugar).
 */
return new class extends Migration
{
    public function up(): void
    {
        $adminId = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
        $oldIds = DB::table('users')->where('role', 'public_place')->pluck('id');

        // Kung walay admin, ayaw hilabti (ayaw pagkawala sa data)
        if (! $adminId || $oldIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($adminId, $oldIds) {
            DB::table('establishments')->whereIn('owner_id', $oldIds)->update(['owner_id' => $adminId]);

            DB::table('personal_access_tokens')
                ->where('tokenable_type', 'App\\Models\\User')
                ->whereIn('tokenable_id', $oldIds)
                ->delete();

            DB::table('users')->whereIn('id', $oldIds)->delete();
        });
    }

    public function down(): void
    {
        // Wala'y balik
    }
};