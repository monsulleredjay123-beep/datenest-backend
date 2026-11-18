<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            // Term sa sunod nga bayad nga gi-set sa admin (isa ra ang naay sulod): pila ka bulan O exact nga petsa
            $table->unsignedSmallInteger('ads_term_months')->nullable()->after('listing_expires_at');
            $table->date('ads_ends_on')->nullable()->after('ads_term_months');
        });
    }

    public function down(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn(['ads_term_months', 'ads_ends_on']);
        });
    }
};