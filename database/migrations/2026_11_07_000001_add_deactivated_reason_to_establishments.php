<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            // 'expired' = awtomatik nga na-deactivate tungod sa na-expire nga contract
            $table->string('deactivated_reason', 20)->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn('deactivated_reason');
        });
    }
};