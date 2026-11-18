<?php

namespace App\Http\Controllers;

use App\Http\Resources\ReservationResource;
use App\Models\Establishment;
use App\Models\Reservation;
use App\Services\Notifier;
use App\Services\PaymentDetails;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    private const WITH = ['customer', 'establishment', 'review.customer'];

    /** Customer: mag-submit ug reservation request (Pending) */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'establishment_id' => ['required', 'integer', 'exists:establishments,id'],
            'date' => [
                'required', 'date_format:Y-m-d',
                'after_or_equal:'.Carbon::today(config('datenest.timezone'))->toDateString(),
                'before_or_equal:'.Carbon::today(config('datenest.timezone'))
                    ->addDays((int) config('datenest.reservation_max_days_ahead', 90))->toDateString(),
            ],
            'time' => ['required', 'date_format:H:i'],
            'guests' => ['required', 'integer', 'min:1', 'max:20'],
            'notes' => ['nullable', 'string', 'max:300'],
        ], [
            'date.after_or_equal' => 'The date cannot be in the past.',
            'date.before_or_equal' => 'You can only reserve up to '
                .config('datenest.reservation_max_days_ahead', 90).' days ahead.',
        ]);

        $establishment = Establishment::findOrFail($data['establishment_id']);

        // Active, approved ug wala pa ma-expire ang advertising contract ra ang pwede ma-reserve
        if (! $establishment->hasActiveListing()) {
            abort(404, 'Establishment not found.');
        }

        // Public place (park, etc.): diretso na, walay reservation
        if (! $establishment->acceptsReservations()) {
            abort(422, 'This place is open to the public and does not take reservations.');
        }

        $this->validateSlot($establishment, $data['date'], $data['time'], $request->user()->id);

        // Inn ug Lodge: kinahanglan ug edad (18+) ug valid ID
        $idFields = [];
        if ($establishment->requiresId()) {
            $guest = $request->validate([
                'guest_id' => ['required', 'array'],
                'guest_id.birthdate' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'guest_id.id_type' => ['required', 'string', 'max:100'],
                'guest_id.id_number' => ['required', 'string', 'min:5', 'max:50'],
                'guest_id.id_photo' => ['required', 'string'],
            ])['guest_id'];

            if (Carbon::parse($guest['birthdate'])->age < Establishment::MIN_STAY_AGE) {
                throw ValidationException::withMessages([
                    'guest_id.birthdate' => 'You must be at least '.Establishment::MIN_STAY_AGE
                        .' years old to reserve an inn or lodge.',
                ]);
            }

            $idFields = [
                'birthdate' => $guest['birthdate'],
                'id_type' => $guest['id_type'],
                'id_number' => $guest['id_number'],
                'id_photo_path' => $this->storeIdPhoto($guest['id_photo']),
            ];
        }

        $fee = (float) ($establishment->reservation_fee ?? 0);

        $reservation = Reservation::create(array_merge([
            'customer_id' => $request->user()->id,
            'establishment_id' => $establishment->id,
            'date' => $data['date'],
            'time' => $data['time'],
            'guests' => $data['guests'],
            'notes' => $data['notes'] ?? null,
            'status' => 'Pending', // Dili gyud auto-approve
            'status_updated_at' => now(),
            'amount_due' => $fee,
            'payment_status' => $fee > 0 ? 'Unpaid' : 'Not Required',
        ], $idFields));

        // Notification sa owner: naay bag-ong request
        $when = Carbon::parse($data['date'].' '.$data['time'])->format('D, M j, Y \a\t g:i A');
        Notifier::to($establishment->owner_id, 'reservation_requested', 'New reservation request',
            "{$request->user()->name} requested {$establishment->name} on {$when} for {$data['guests']} guest(s).", '/owner/requests');

        return (new ReservationResource($reservation->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    /** Customer: akong mga reservation */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $list = Reservation::where('customer_id', $request->user()->id)
            ->with(self::WITH)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return ReservationResource::collection($list);
    }

    /**
     * Bayad sa reservation fee, human ma-Approve.
     * DEMO: walay tinuod nga gateway. Ang method (GCash/Maya/Card) ra ang i-save,
     * dili ang card details.
     */
    public function pay(Request $request, Reservation $reservation): ReservationResource
    {
        Gate::authorize('own', $reservation);

        // Detalye sa bayad (mobile / card): gi-validate. Ang card number ug CVC dili i-save.
        $payment = PaymentDetails::validate($request);

        if ($reservation->status !== 'Approved') {
            abort(422, 'You can only pay after the establishment approves your request.');
        }
        if ($reservation->payment_status === 'Paid') {
            abort(422, 'This reservation is already paid.');
        }
        if ($reservation->payment_status === 'Not Required') {
            abort(422, 'No payment is required for this reservation.');
        }

        // Dili na pwede bayran kung nilapas na ang oras sa reservation
        $startsAt = Carbon::parse($reservation->date->format('Y-m-d').' '.substr((string) $reservation->time, 0, 5), config('datenest.timezone'));
        if ($startsAt->isPast()) {
            abort(422, 'The time of this reservation has already passed, so it can no longer be paid.');
        }

        // Atomic: dili mabayran kaduha bisan doble ang click
        $updated = Reservation::whereKey($reservation->id)->where('payment_status', 'Unpaid')->update([
            'payment_status' => 'Paid',
            'payment_method' => $payment['method'],
            'payment_account' => $payment['account'],
            'payment_ref' => $this->newPaymentRef(),
            'paid_at' => now(),
        ]);
        if (! $updated) {
            abort(422, 'This reservation is already paid.');
        }

        $reservation->refresh();
        $reservation->load('establishment');
        $amount = number_format((float) $reservation->amount_due, 2);
        Notifier::to($reservation->establishment->owner_id, 'reservation_paid', 'Reservation fee paid',
            "{$request->user()->name} paid ₱{$amount} (ref. {$reservation->payment_ref}) for the reservation on {$startsAt->format('M j, Y g:i A')}.",
            '/owner/requests');

        return new ReservationResource($reservation->load(self::WITH));
    }

    /** Unique nga reference number sa payment, pananglitan DN-20261107-A8K3ZQ */
    private function newPaymentRef(): string
    {
        do {
            $ref = 'DN-'.Carbon::now(config('datenest.timezone'))->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (Reservation::where('payment_ref', $ref)->exists());

        return $ref;
    }

    /**
     * Validation sa oras: matag 30 minutos, sulod sa opening hours (ang kataposang slot 1 ka oras
     * sa dili pa mo-close), dili lapas na, labing gamay 30 minutos gikan karon, ug walay doble nga request.
     */
    private function validateSlot(Establishment $e, string $date, string $time, int $customerId): void
    {
        $tz = config('datenest.timezone');
        $step = (int) config('datenest.reservation_slot_minutes', 30);
        $lead = (int) config('datenest.reservation_lead_minutes', 30);
        $lastBefore = (int) config('datenest.reservation_last_slot_before_close', 60);

        $when = Carbon::createFromFormat('Y-m-d H:i', $date.' '.$time, $tz);

        if ($when->minute % $step !== 0) {
            $this->timeError('Please choose a time on the hour or half hour (for example 6:00 PM or 6:30 PM).');
        }

        if ($when->lt(Carbon::now($tz)->addMinutes($lead))) {
            $this->timeError('Please choose a time at least '.$lead.' minutes from now.');
        }

        // Sulod sa opening hours
        $open = $this->minutes($e->opening_time);
        $close = $this->minutes($e->closing_time);
        $t = $when->hour * 60 + $when->minute;
        $allDay = $open === $close || ($open === 0 && $close >= 23 * 60 + 59);

        if (! $allDay) {
            $last = $close - $lastBefore;
            $ok = $close > $open
                ? ($t >= $open && $t <= $last)
                : ($t >= $open || $t <= $last); // overnight (pananglitan 6 PM hangtod 2 AM)

            if (! $ok) {
                $this->timeError(sprintf(
                    'This place takes reservations from %s up to %d minutes before it closes at %s.',
                    Carbon::createFromFormat('H:i', substr((string) $e->opening_time, 0, 5))->format('g:i A'),
                    $lastBefore,
                    Carbon::createFromFormat('H:i', substr((string) $e->closing_time, 0, 5))->format('g:i A'),
                ));
            }
        }

        // Walay doble nga request sa parehas nga petsa ug oras
        $duplicate = Reservation::where('customer_id', $customerId)
            ->where('establishment_id', $e->id)
            ->whereDate('date', $date)
            ->whereIn('time', [$time, $time.':00'])
            ->whereIn('status', ['Pending', 'Approved'])
            ->exists();

        if ($duplicate) {
            $this->timeError('You already have a reservation request for this place at that date and time.');
        }
    }

    private function minutes(mixed $time): int
    {
        [$h, $m] = array_map('intval', explode(':', substr((string) $time, 0, 5)));

        return $h * 60 + $m;
    }

    private function timeError(string $message): never
    {
        throw ValidationException::withMessages(['time' => $message]);
    }

    /** I-save ang ID photo sa PRIVATE storage ug i-return ang path */
    private function storeIdPhoto(string $dataUrl): string
    {
        $comma = strpos($dataUrl, ',');
        $header = $comma === false ? '' : substr($dataUrl, 0, $comma);

        if (strlen($dataUrl) > 5_000_000
            || ! preg_match('#^data:image/(jpeg|jpg|png|webp);base64$#', $header, $m)) {
            throw ValidationException::withMessages([
                'guest_id.id_photo' => 'The ID photo must be a JPG, PNG, or WEBP image (max 3 MB).',
            ]);
        }

        $binary = base64_decode(substr($dataUrl, $comma + 1), true);

        if ($binary === false || strlen($binary) > 3 * 1024 * 1024 || @getimagesizefromstring($binary) === false) {
            throw ValidationException::withMessages([
                'guest_id.id_photo' => 'The ID photo is invalid or too large (max 3 MB).',
            ]);
        }

        $ext = $m[1] === 'jpeg' ? 'jpg' : $m[1];
        $path = 'ids/'.Str::uuid().'.'.$ext;
        Storage::disk('local')->put($path, $binary);

        return $path;
    }
}