<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('establishments', 'approval_status')) {
            Schema::table('establishments', function (Blueprint $table) {
                $table->string('approval_status')->default('pending');
            });
        }

        if (! Schema::hasColumn('establishments', 'submitted_at')) {
            Schema::table('establishments', function (Blueprint $table) {
                // Kanus-a gi-submit para ma-review (mo-restart kung mag-resubmit human ma-reject)
                $table->timestamp('submitted_at')->nullable()->after('approval_status');
            });
        }

        if (! Schema::hasColumn('establishments', 'permit_expires_at')) {
            Schema::table('establishments', function (Blueprint $table) {
                // Expiry date sa business permit (gi-enter sa admin human ma-check ang permit)
                $table->date('permit_expires_at')->nullable()->after('submitted_at');
            });
        }

        if (! Schema::hasColumn('establishments', 'details_verified_at')) {
            Schema::table('establishments', function (Blueprint $table) {
                // Kanus-a gi-verify sa admin ang details sa owner
                $table->timestamp('details_verified_at')->nullable()->after('permit_expires_at');
            });
        }

        DB::table('establishments')
            ->whereNull('submitted_at')
            ->update(['submitted_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn(['submitted_at', 'permit_expires_at', 'details_verified_at']);
        });
    }
};