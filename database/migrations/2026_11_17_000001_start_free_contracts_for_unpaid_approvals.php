<?php

use App\Models\Establishment;
use Illuminate\Database\Migrations\Migration;

/**
 * LIBRE na ang ads. Ang mga establishment nga na-approve na pero wala pa makabayad (naghulat sa bayad)
 * i-start dayon ang ilang contract, aron dili sila ma-stuck.
 */
return new class extends Migration
{
    public function up(): void
    {
        Establishment::query()
            ->where('is_private', true)
            ->where('approval_status', 'approved')
            ->where(fn ($q) => $q->whereNull('listing_expires_at')->orWhereDate('listing_expires_at', '<', today()))
            ->get()
            ->each(function (Establishment $e) {
                $period = $e->adsPeriod();
                if (! $period) {
                    return; // wala pa term/permit: ang owner mo-renew
                }

                $e->contracts()->create([
                    'type' => $e->contracts()->where('status', 'approved')->exists() ? 'renewal' : 'initial',
                    'duration_months' => $period['months'],
                    'amount' => 0,
                    'status' => 'approved',
                    'starts_at' => $period['starts_at'],
                    'ends_at' => $period['ends_at'],
                    'decided_at' => now(),
                ]);

                $e->update([
                    'listing_starts_at' => $period['starts_at'],
                    'listing_expires_at' => $period['ends_at'],
                    'ads_term_months' => null,
                    'ads_ends_on' => null,
                    'is_active' => $e->deactivated_reason === 'expired' ? true : $e->is_active,
                    'deactivated_reason' => $e->deactivated_reason === 'expired' ? null : $e->deactivated_reason,
                ]);
            });
    }

    public function down(): void
    {
        // Wala'y balik.
    }
};
