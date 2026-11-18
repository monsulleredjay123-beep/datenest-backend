<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            // Gi-request sa owner (para ma-double check sa admin): expiry sa permit nga iyang gi-declare + gusto nga term
            $table->date('declared_permit_expires_at')->nullable()->after('ads_ends_on');
            $table->string('requested_term_type', 10)->nullable()->after('declared_permit_expires_at'); // months | date
            $table->unsignedSmallInteger('requested_term_months')->nullable()->after('requested_term_type');
            $table->date('requested_term_ends_on')->nullable()->after('requested_term_months');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn([
                'declared_permit_expires_at', 'requested_term_type', 'requested_term_months', 'requested_term_ends_on',
            ]);
        });
    }
};