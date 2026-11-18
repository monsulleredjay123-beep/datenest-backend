<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bag-ong flow:
 * - Rejected nga registration: ma-delete awtomatik human sa 2 ka adlaw (rejected_at)
 * - Ang admin mag-check sa mga document (checklist gi-save sa establishment_approvals.checks)
 * - Ang advertising contract bayran na (amount, method, reference no.). Kung bayad na, live na.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->timestamp('rejected_at')->nullable()->after('rejection_reason');
        });

        Schema::table('establishment_approvals', function (Blueprint $table) {
            $table->json('checks')->nullable()->after('remarks');
        });

        Schema::table('establishment_contracts', function (Blueprint $table) {
            $table->decimal('amount', 10, 2)->nullable()->after('duration_months');
            $table->string('payment_method', 20)->nullable()->after('amount');
            $table->string('payment_ref', 40)->nullable()->unique()->after('payment_method');
            $table->timestamp('paid_at')->nullable()->after('payment_ref');
        });

        // Wala nay "pending/rejected" nga contract: contract = bayad na. Hugasan ang daan nga mga request.
        DB::table('establishment_contracts')->whereIn('status', ['pending', 'rejected'])->delete();

        // Ang na-reject na karon: hatagan ug 2 ka adlaw gikan karon sa dili pa ma-delete
        DB::table('establishments')
            ->where('approval_status', 'rejected')
            ->whereNull('rejected_at')
            ->update(['rejected_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('establishment_contracts', function (Blueprint $table) {
            $table->dropUnique(['payment_ref']);
            $table->dropColumn(['amount', 'payment_method', 'payment_ref', 'paid_at']);
        });

        Schema::table('establishment_approvals', function (Blueprint $table) {
            $table->dropColumn('checks');
        });

        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn('rejected_at');
        });
    }
};