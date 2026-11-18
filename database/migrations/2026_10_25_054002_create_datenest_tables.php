<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('establishments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->enum('category', ['cafe', 'restaurant', 'inn', 'lodge', 'destination']);
            $table->text('description');
            $table->string('address');
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->time('opening_time');
            $table->time('closing_time');
            $table->decimal('price', 10, 2)->nullable();            // starting rate (null = walay rate)
            $table->decimal('reservation_fee', 10, 2)->nullable();  // bayran online human ma-approve
            $table->boolean('is_active')->default(true);
            $table->decimal('avg_rating', 3, 2)->default(0);
            $table->timestamps();

            $table->index(['is_active', 'category']);
        });

        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('preferences', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('establishment_amenity', function (Blueprint $table) {
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained()->cascadeOnDelete();
            $table->primary(['establishment_id', 'amenity_id']);
        });

        Schema::create('establishment_preference', function (Blueprint $table) {
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('preference_id')->constrained()->cascadeOnDelete();
            $table->primary(['establishment_id', 'preference_id']);
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->timestamps();
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->time('time');
            $table->unsignedSmallInteger('guests');
            $table->text('notes')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Rejected', 'Completed'])->default('Pending');
            $table->timestamp('status_updated_at')->nullable();

            // Payment
            $table->decimal('amount_due', 10, 2)->default(0);
            $table->enum('payment_status', ['Not Required', 'Unpaid', 'Paid'])->default('Not Required');
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_ref', 40)->nullable();
            $table->timestamp('paid_at')->nullable();

            // Inn / Lodge: edad ug valid ID (private)
            $table->date('birthdate')->nullable();
            $table->string('id_type', 100)->nullable();
            $table->text('id_number')->nullable();       // encrypted
            $table->string('id_photo_path')->nullable(); // private storage

            $table->timestamps();

            $table->index(['establishment_id', 'status']);
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            // nullable = para sa sample reviews; unique = usa ra ka review kada reservation
            $table->foreignId('reservation_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('photos');
        Schema::dropIfExists('establishment_preference');
        Schema::dropIfExists('establishment_amenity');
        Schema::dropIfExists('preferences');
        Schema::dropIfExists('amenities');
        Schema::dropIfExists('establishments');
    }
};