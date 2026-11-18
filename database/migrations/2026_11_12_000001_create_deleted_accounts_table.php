<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mga email sa account nga gi-delete sa admin, para ma-ingnan ang user kung mosulay siya og login
        Schema::create('deleted_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique(); // lowercase
            $table->string('name')->nullable();
            $table->string('role', 30)->nullable();
            // ID sa admin nga nag-delete (walay foreign key, para dili maapektuhan kung ma-delete ang admin)
            $table->unsignedBigInteger('deleted_by')->nullable();
            $table->timestamp('deleted_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleted_accounts');
    }
};