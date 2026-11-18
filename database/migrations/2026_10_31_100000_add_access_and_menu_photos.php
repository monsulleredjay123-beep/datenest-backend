<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('establishments', function (Blueprint $table) {
            // true = private (naay reservation), false = public (diretso na, walay reservation)
            $table->boolean('is_private')->default(true)->after('category');
        });

        // Ang date destinations (parks, etc.) public ra by default. Mahimo ra i-usab sa owner.
        DB::table('establishments')
            ->where('category', 'destination')
            ->update(['is_private' => false, 'reservation_fee' => null]);

        // Mga picture sa menu (puli sa daang menu items)
        Schema::create('menu_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->longText('url');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_photos');

        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }
};