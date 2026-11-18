<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ang kulang sa approval + contract system:
 * - mga column sa establishments (rejection_reason, approved_at, listing_starts_at, listing_expires_at)
 * - table establishment_approvals (desisyon sa admin)
 * - table establishment_contracts (initial ug renewal)
 * - ang naa nang establishments: i-approve ug hatagan ug 12-month nga contract aron dili sila mawala
 */
return new class extends Migration
{
    public function up(): void
    {
        $add = function (string $column, callable $define) {
            if (! Schema::hasColumn('establishments', $column)) {
                Schema::table('establishments', fn (Blueprint $t) => $define($t));
            }
        };

        $add('approval_status', fn ($t) => $t->string('approval_status')->default('pending'));
        $add('rejection_reason', fn ($t) => $t->text('rejection_reason')->nullable());
        $add('approved_at', fn ($t) => $t->timestamp('approved_at')->nullable());
        $add('listing_starts_at', fn ($t) => $t->date('listing_starts_at')->nullable());
        $add('listing_expires_at', fn ($t) => $t->date('listing_expires_at')->nullable());

        if (! Schema::hasTable('establishment_approvals')) {
            Schema::create('establishment_approvals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
                $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
                $table->string('decision', 20); // approved | rejected
                $table->text('remarks')->nullable();
                $table->timestamps();

                $table->unique(['establishment_id', 'admin_id']); // usa ka boto kada admin
            });
        }

        if (! Schema::hasTable('establishment_contracts')) {
            Schema::create('establishment_contracts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('establishment_id')->constrained()->cascadeOnDelete();
                $table->string('type', 20)->default('renewal'); // initial | renewal
                $table->unsignedSmallInteger('duration_months');
                $table->string('status', 20)->default('pending'); // pending | approved | rejected
                $table->date('starts_at')->nullable();
                $table->date('ends_at')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();

                $table->index(['establishment_id', 'status']);
            });
        }

        // Ang naa nang establishments (wala pa ni-agi sa review): approved na, 12 ka bulan gikan karon
        $start = Carbon::today(config('app.timezone'));
        $end = $start->copy()->addMonthsNoOverflow(12)->subDay();

        $legacy = DB::table('establishments')
            ->whereNull('listing_expires_at')
            ->where('approval_status', 'pending')
            ->pluck('id');

        foreach ($legacy as $id) {
            DB::table('establishments')->where('id', $id)->update([
                'approval_status' => 'approved',
                'approved_at' => now(),
                'listing_starts_at' => $start->toDateString(),
                'listing_expires_at' => $end->toDateString(),
            ]);

            DB::table('establishment_contracts')->insert([
                'establishment_id' => $id,
                'type' => 'initial',
                'duration_months' => 12,
                'status' => 'approved',
                'starts_at' => $start->toDateString(),
                'ends_at' => $end->toDateString(),
                'remarks' => 'Initial contract for existing listings.',
                'decided_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('establishment_contracts');
        Schema::dropIfExists('establishment_approvals');

        Schema::table('establishments', function (Blueprint $table) {
            $table->dropColumn(['rejection_reason', 'approved_at', 'listing_starts_at', 'listing_expires_at']);
        });
    }
};