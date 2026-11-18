<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Para ma-save ang base64 photos (dako kaayo sa varchar)
        Schema::table('photos', function (Blueprint $table) {
            $table->longText('url')->change();
        });

        // Optional business permit sa establishment owners
        Schema::table('users', function (Blueprint $table) {
            $table->longText('business_permit')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('business_permit');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->string('url', 500)->change();
        });
    }
};