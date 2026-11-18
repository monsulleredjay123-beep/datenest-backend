<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mga notification sa matag user (admin, owner, customer)
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('title', 150);
            $table->text('body');
            $table->string('link', 150)->nullable(); // route sa app, pananglitan /owner/ads
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'id']);
        });

        // Gi-mask nga account sa bayad (last 4 ra: ****4567). Dili gyud i-save ang tibuok card/CVC.
        Schema::table('reservations', function (Blueprint $table) {
            $table->string('payment_account', 20)->nullable()->after('payment_ref');
            $table->timestamp('reminded_at')->nullable(); // gi-remind na ba ang customer/owner (adlaw sa dili pa)
        });

        Schema::table('establishment_contracts', function (Blueprint $table) {
            $table->string('payment_account', 20)->nullable()->after('payment_ref');
        });

        // Para dili doble ang reminder nga "ma-expire na ang ads": ang expiry date nga na-remind na
        Schema::table('establishments', function (Blueprint $table) {
            $table->date('expiry_reminded_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('establishments', fn (Blueprint $t) => $t->dropColumn('expiry_reminded_for'));
        Schema::table('establishment_contracts', fn (Blueprint $t) => $t->dropColumn('payment_account'));
        Schema::table('reservations', fn (Blueprint $t) => $t->dropColumn(['payment_account', 'reminded_at']));
        Schema::dropIfExists('user_notifications');
    }
};