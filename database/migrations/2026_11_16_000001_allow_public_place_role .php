<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bag-ong role: 'public_place' (account para sa mga lugar nga bukas sa tanan).
 * Ang `users.role` kay enum sa MySQL, mao nga himoon nato nga string aron madugangan ug role.
 * Gi-ilisan usab: libre na ang ads, mao nga ang contract amount sa daan nga records pabiling kung unsa na.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role VARCHAR(30) NOT NULL DEFAULT 'customer'");
        }
        // sqlite (test): ang enum kay CHECK constraint; i-recreate ang database kung kinahanglan.
    }

    public function down(): void
    {
        // Wala'y balik: dili na i-enum ang role.
    }
};
