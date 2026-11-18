<?php

namespace App\Console\Commands;

use App\Models\Establishment;
use App\Models\Reservation;
use App\Services\Notifier;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Mga reminder (matag adlaw):
 * - Owner: ma-expire na ang ads sulod sa ilang adlaw
 * - Customer ug owner: ugma na ang approved nga reservation
 */
class SendReminders extends Command
{
    protected $signature = 'datenest:send-reminders';

    protected $description = 'Send reminders for expiring ads and tomorrow\'s reservations';

    public function handle(): int
    {
        $tz = config('datenest.timezone');
        $today = Carbon::today($tz);
        $days = (int) config('datenest.ads_reminder_days', 7);
        $sent = 0;

        // 1) Ads nga ma-expire na
        Establishment::query()
            ->where('is_active', true)
            ->where('approval_status', 'approved')
            ->whereDate('listing_expires_at', '>=', $today->toDateString())
            ->whereDate('listing_expires_at', '<=', $today->copy()->addDays($days)->toDateString())
            ->get()
            ->each(function (Establishment $e) use ($today, &$sent) {
                // usa ra ka reminder kada expiry date
                if ($e->expiry_reminded_for && $e->expiry_reminded_for->equalTo($e->listing_expires_at)) {
                    return;
                }
                $left = $today->diffInDays($e->listing_expires_at, false);
                $when = $left <= 0 ? 'today' : ($left === 1 ? 'tomorrow' : "in {$left} days");
                Notifier::to($e->owner_id, 'ads_expiring', 'Your ads are ending soon',
                    "The advertising contract of {$e->name} ends {$when} ({$e->listing_expires_at->format('M j, Y')}). Renew it to stay visible to customers.",
                    '/owner/ads');
                $e->update(['expiry_reminded_for' => $e->listing_expires_at]);
                $sent++;
            });

        // 2) Reservation ugma
        Reservation::with('establishment')
            ->where('status', 'Approved')
            ->whereNull('reminded_at')
            ->whereDate('date', $today->copy()->addDay()->toDateString())
            ->get()
            ->each(function (Reservation $r) use (&$sent) {
                $time = Carbon::createFromFormat('H:i', substr((string) $r->time, 0, 5))->format('g:i A');
                $unpaid = $r->payment_status === 'Unpaid' ? ' Your reservation fee is still unpaid.' : '';
                Notifier::to($r->customer_id, 'reservation_reminder', 'Your date is tomorrow',
                    "Reminder: {$r->establishment->name} tomorrow at {$time} for {$r->guests} guest(s).{$unpaid}",
                    $unpaid ? '/customer/pay/'.$r->id : '/customer/reservations');
                Notifier::to($r->establishment->owner_id, 'reservation_reminder', 'Reservation tomorrow',
                    "A reservation for {$r->guests} guest(s) is scheduled tomorrow at {$time}.", '/owner/requests');
                $r->update(['reminded_at' => now()]);
                $sent++;
            });

        $this->info("Sent {$sent} reminder(s).");

        return self::SUCCESS;
    }
}