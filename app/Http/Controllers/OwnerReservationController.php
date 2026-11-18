<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservationResource;
use App\Models\Reservation;
use App\Services\Notifier;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class OwnerReservationController extends Controller
{
    private const WITH = ['customer', 'establishment', 'review.customer'];

    /** Tanang request sa establishment sa owner */
    public function index(Request $request): AnonymousResourceCollection
    {
        $ownerId = $request->user()->id;

        $list = Reservation::whereHas('establishment', fn ($q) => $q->where('owner_id', $ownerId))
            ->with(self::WITH)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ReservationResource::collection($list);
    }

    public function approve(Reservation $reservation): ReservationResource
    {
        return $this->change($reservation, 'Pending', 'Approved', 'Only pending requests can be approved or rejected.');
    }

    public function reject(Reservation $reservation): ReservationResource
    {
        return $this->change($reservation, 'Pending', 'Rejected', 'Only pending requests can be approved or rejected.');
    }

    public function complete(Reservation $reservation): ReservationResource
    {
        return $this->change($reservation, 'Approved', 'Completed', 'Only approved reservations can be marked as completed.');
    }

    private function change(Reservation $reservation, string $from, string $to, string $error): ReservationResource
    {
        Gate::authorize('manage', $reservation); // owner sa establishment ra

        if ($reservation->status !== $from) {
            abort(422, $error);
        }

        $tz = config('datenest.timezone');
        $startsAt = Carbon::parse($reservation->date->format('Y-m-d').' '.substr((string) $reservation->time, 0, 5), $tz);

        // Logic sa oras: dili ma-approve ang nilapas na; dili ma-complete kung wala pa ang adlaw sa reservation
        if ($to === 'Approved' && $startsAt->isPast()) {
            abort(422, 'The time of this reservation has already passed, so it can no longer be approved. You can reject it instead.');
        }
        if ($to === 'Completed' && Carbon::now($tz)->toDateString() < $startsAt->toDateString()) {
            abort(422, 'You can only mark a reservation as completed on or after its reservation date ('.$startsAt->format('M j, Y').').');
        }

        $reservation->update([
            'status' => $to,
            'status_updated_at' => now(), // i-save ang petsa ug oras sa matag change
        ]);

        $this->notifyCustomer($reservation, $to, $startsAt);

        return new ReservationResource($reservation->load(self::WITH));
    }

    /** Ipahibalo sa customer ang nahitabo sa iyang reservation */
    private function notifyCustomer(Reservation $reservation, string $to, Carbon $startsAt): void
    {
        $place = $reservation->establishment->name;
        $when = $startsAt->format('D, M j, Y \a\t g:i A');

        match ($to) {
            'Approved' => Notifier::to($reservation->customer_id, 'reservation_approved', 'Reservation approved',
                $reservation->payment_status === 'Unpaid'
                    ? "{$place} approved your reservation on {$when}. Please pay the reservation fee of ₱".number_format((float) $reservation->amount_due, 2).' to secure it.'
                    : "{$place} approved your reservation on {$when}.",
                $reservation->payment_status === 'Unpaid' ? '/customer/pay/'.$reservation->id : '/customer/reservations'),
            'Rejected' => Notifier::to($reservation->customer_id, 'reservation_rejected', 'Reservation declined',
                "{$place} could not accept your reservation on {$when}. You can try another date or place.", '/customer/reservations'),
            'Completed' => Notifier::to($reservation->customer_id, 'reservation_completed', 'How was your visit?',
                "Thanks for visiting {$place}! Leave a review to help other couples.", '/customer/review/'.$reservation->id),
            default => null,
        };
    }
}