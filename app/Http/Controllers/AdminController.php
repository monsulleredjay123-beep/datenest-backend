<?php

namespace App\Http\Controllers;

use App\Http\Resources\EstablishmentResource;
use App\Http\Resources\UserResource;
use App\Models\Establishment;
use App\Models\EstablishmentContract;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminController extends Controller
{
    private const EST_WITH = ['preferences', 'amenities', 'photos', 'approvals.admin', 'owner:id,name,email'];

    /** Mga document nga kinahanglan i-check sa admin sa dili pa ma-approve ang registration (tanan kinahanglan) */
    public const DOCUMENT_CHECKS = ['business_permit', 'owner_details', 'establishment_details'];

    public function users(): AnonymousResourceCollection
    {
        return UserResource::collection(User::orderBy('id')->get());
    }

    public function toggleUser(User $user): UserResource
    {
        if ($user->role === 'admin') {
            abort(422, 'Admin accounts cannot be deactivated.');
        }

        $user->is_active = ! $user->is_active;
        $user->save();

        // Kung gi-deactivate: i-logout sa tanang device
        if (! $user->is_active) {
            $user->tokens()->delete();
        }

        return new UserResource($user);
    }

    /**
     * Admin: i-delete ang account sa customer o owner (permanente, dili na ma-undo).
     * Kauban ang listing, photos, ads payment records, reservations ug reviews (cascade sa database).
     * Kinahanglan i-type ang email sa account para makumpirma. Ang admin accounts dili pwede ma-delete.
     */
    public function destroyUser(Request $request, User $user): JsonResponse
    {
        if ($user->role === 'admin') {
            abort(422, 'Admin accounts cannot be deleted.');
        }

        $data = $request->validate(
            ['confirm_email' => ['required', 'string']],
            ['confirm_email.required' => 'Please type the email of the account to confirm.'],
        );

        if (mb_strtolower(trim($data['confirm_email'])) !== mb_strtolower($user->email)) {
            throw ValidationException::withMessages([
                'confirm_email' => 'The email you typed does not match this account.',
            ]);
        }

        $name = $user->name;
        $adminId = $request->user()->id;
        $notified = 0;

        DB::transaction(function () use ($user, $adminId, &$notified) {
            $open = ['Pending', 'Approved'];

            // I-tala ang email sa na-delete nga account, para ma-ingnan siya sa login nga na-delete na ang account
            DB::table('deleted_accounts')->updateOrInsert(
                ['email' => mb_strtolower($user->email)],
                ['name' => $user->name, 'role' => $user->role, 'deleted_by' => $adminId, 'deleted_at' => now()],
            );

            if ($user->role === 'establishment_admin') {
                // Ipahibalo ang mga customer nga naay naghulat o aprubado nga reservation sa lugar sa owner
                $ids = $user->establishments()->pluck('id');
                Reservation::whereIn('establishment_id', $ids)
                    ->whereIn('status', $open)
                    ->whereDate('date', '>=', today())
                    ->with('establishment:id,name')
                    ->get()
                    ->each(function (Reservation $r) use (&$notified) {
                        Notifier::to($r->customer_id, 'reservation_cancelled', 'Reservation cancelled',
                            "Your reservation at {$r->establishment?->name} was cancelled because the establishment is no longer on DateNest.",
                            '/customer/reservations');
                        $notified++;
                    });
            } else {
                // Customer: ipahibalo ang mga owner nga naay naghulat o aprubado nga reservation gikan niya
                Reservation::where('customer_id', $user->id)
                    ->whereIn('status', $open)
                    ->whereDate('date', '>=', today())
                    ->with('establishment:id,name,owner_id')
                    ->get()
                    ->each(function (Reservation $r) use ($user, &$notified) {
                        Notifier::to($r->establishment?->owner_id, 'reservation_cancelled', 'Reservation removed',
                            "A reservation at {$r->establishment?->name} from {$user->name} was removed because the customer's account was deleted.",
                            '/owner/requests');
                        $notified++;
                    });
            }

            $user->tokens()->delete(); // i-logout ang tanang device
            $user->delete(); // cascade: establishments, photos, menus, contracts, reservations, reviews, notifications
        });

        return response()->json([
            'message' => "The account of {$name} was deleted.",
            'notified' => $notified,
        ]);
    }

    /**
     * Listahan sa establishments. ?status=pending|rejected = Registrations;
     * ?status=approved = Establishments (naka-approve na). Walay status = tanan.
     */
    public function establishments(Request $request): AnonymousResourceCollection
    {
        // safety net kung wala naka-run ang scheduler
        Establishment::deactivateExpired();
        Establishment::purgeRejected();

        $status = $request->query('status');

        $list = Establishment::with(self::EST_WITH)
            ->when(in_array($status, ['pending', 'approved', 'rejected'], true), fn ($q) => $q->where('approval_status', $status))
            ->orderBy('submitted_at') // pinakadaan nga registration una
            ->orderBy('id')
            ->get();

        return EstablishmentResource::collection($list);
    }

    public function toggleEstablishment(Establishment $establishment): EstablishmentResource
    {
        if ($establishment->approval_status !== 'approved') {
            abort(422, 'Only approved establishments can be activated or deactivated.');
        }

        // Dili pwede i-activate ang na-expire nga contract; kinahanglan mo-renew (bayad) ang owner.
        if (! $establishment->is_active
            && $establishment->listing_expires_at
            && $establishment->listing_expires_at->lt(today())) {
            abort(422, 'The advertising contract of this establishment has expired. It will be active again once the owner renews and pays.');
        }

        $establishment->is_active = ! $establishment->is_active;
        $establishment->deactivated_reason = null; // mano-mano ni nga desisyon sa admin
        $establishment->save();

        Notifier::to($establishment->owner_id,
            $establishment->is_active ? 'listing_activated' : 'listing_deactivated',
            $establishment->is_active ? 'Your listing is active again' : 'Your listing was deactivated',
            $establishment->is_active
                ? "{$establishment->name} is visible to customers again."
                : "{$establishment->name} was deactivated by the administrator and is hidden from customers. Please contact the admin.",
            '/owner');

        return new EstablishmentResource($establishment->load(self::EST_WITH));
    }

    // ================= REGISTRATION APPROVAL (document check) =================

    /** Mga requirement sa owner (business permit, logo) para ma-review sa admin */
    public function reviewEstablishment(Establishment $establishment): JsonResponse
    {
        $owner = $establishment->owner;

        return response()->json([
            'owner' => [
                'name' => $owner?->name,
                'email' => $owner?->email,
                'birthdate' => $owner?->birthdate?->format('Y-m-d'),
                'logo' => $owner?->logo,
                'business_permit' => $owner?->business_permit, // hidden sa serialization, mao gi-kuha diri
            ],
        ]);
    }

    /**
     * Pwede ra ma-approve kung nahuman na ang pag-check sa tanang document:
     * naay business permit, wala pa ma-expire, na-tick ang tanang item sa checklist,
     * ug naay husto nga term sa ads (bulan/tuig o exact nga petsa) nga dili molapas sa permit.
     */
    public function approveEstablishment(Request $request, Establishment $establishment): EstablishmentResource
    {
        $minMonths = (int) config('datenest.contract_min_months', 1);
        $maxMonths = (int) config('datenest.contract_max_months', 60);

        $data = $request->validate([
            'remarks' => ['nullable', 'string', 'max:500'],
            // Expiry date sa business permit (gi-basa sa admin gikan sa permit)
            'permit_expires_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'checks' => ['required', 'array'],
            'checks.*' => ['string', Rule::in(self::DOCUMENT_CHECKS)],
            // Term sa ads: pila ka bulan/tuig (months) O exact nga petsa (date), base sa business permit
            'term_type' => ['required', Rule::in(['months', 'date'])],
            'term_months' => ['required_if:term_type,months', 'nullable', 'integer', "min:{$minMonths}", "max:{$maxMonths}"],
            'term_ends_on' => ['required_if:term_type,date', 'nullable', 'date_format:Y-m-d', 'after:today'],
        ], [
            'permit_expires_at.after_or_equal' => 'The business permit is expired, so this registration cannot be approved. Reject it instead.',
            'checks.required' => 'Please finish checking all the documents first.',
            'term_type.required' => 'Please choose the advertising term.',
            'term_months.required_if' => 'Please enter how many months.',
            'term_months.integer' => 'The number of months must be a whole number.',
            'term_months.min' => "The term must be at least {$minMonths} month.",
            'term_months.max' => "The term cannot be more than {$maxMonths} months.",
            'term_ends_on.required_if' => 'Please choose the exact end date.',
            'term_ends_on.after' => 'The end date must be after today.',
        ]);

        // Logic: ang term dili pwede molapas sa expiry sa business permit
        $permitEnd = Carbon::parse($data['permit_expires_at'])->startOfDay();
        if ($data['term_type'] === 'months') {
            $months = (int) $data['term_months'];
            $end = today()->addMonthsNoOverflow($months)->subDay();
            if ($end->gt($permitEnd)) {
                throw ValidationException::withMessages([
                    'term_months' => "A {$months}-month term ends on {$end->format('M j, Y')}, which is after the business permit expires ({$permitEnd->format('M j, Y')}). Choose a shorter term or set the exact end date.",
                ]);
            }
        } else {
            $end = Carbon::parse($data['term_ends_on'])->startOfDay();
            if ($end->gt($permitEnd)) {
                throw ValidationException::withMessages([
                    'term_ends_on' => "The end date cannot be after the business permit expires ({$permitEnd->format('M j, Y')}).",
                ]);
            }
            if ($end->gt(today()->addMonthsNoOverflow($maxMonths))) {
                throw ValidationException::withMessages([
                    'term_ends_on' => "The end date cannot be more than {$maxMonths} months from today.",
                ]);
            }
        }

        $missing = array_diff(self::DOCUMENT_CHECKS, $data['checks']);
        if ($missing) {
            abort(422, 'Please finish checking all the documents before approving.');
        }
        if (! $establishment->owner?->business_permit) {
            abort(422, 'The owner did not upload a business permit, so this registration cannot be approved.');
        }

        $wasRenewal = $establishment->isRenewal(); // gi-approve na kaniadto?

        DB::transaction(function () use ($request, $establishment, $data) {
            $e = Establishment::whereKey($establishment->id)->lockForUpdate()->firstOrFail();

            if ($e->approval_status !== 'pending') {
                abort(422, 'This registration is no longer pending.');
            }
            if ($e->approvals()->where('admin_id', $request->user()->id)->exists()) {
                abort(422, 'You already reviewed this registration. A different admin must give the next approval.');
            }

            $e->update([
                'permit_expires_at' => $data['permit_expires_at'],
                'details_verified_at' => now(),
                // Ang term nga gi-set sa admin (isa ra ang naay sulod)
                'ads_term_months' => $data['term_type'] === 'months' ? (int) $data['term_months'] : null,
                'ads_ends_on' => $data['term_type'] === 'date' ? $data['term_ends_on'] : null,
            ]);

            $e->approvals()->create([
                'admin_id' => $request->user()->id,
                'decision' => 'approved',
                'remarks' => $data['remarks'] ?? null,
                'checks' => array_values($data['checks']),
            ]);

            $approved = $e->approvals()->where('decision', 'approved')->count();
            if ($approved >= (int) config('datenest.required_approvals', 1)) {
                $e->update([
                    'approval_status' => 'approved',
                    'rejection_reason' => null,
                    'rejected_at' => null,
                    'approved_at' => now(),
                ]);

                // LIBRE ang ads: human ma-approve, magsugod dayon ang contract (walay bayad).
                $period = $e->adsPeriod();
                if ($period) {
                    $e->contracts()->create([
                        'type' => $e->contracts()->where('status', 'approved')->exists() ? 'renewal' : 'initial',
                        'duration_months' => $period['months'],
                        'amount' => 0,
                        'status' => 'approved',
                        'starts_at' => $period['starts_at'],
                        'ends_at' => $period['ends_at'],
                        'decided_by' => $request->user()->id,
                        'decided_at' => now(),
                    ]);

                    $e->update([
                        'listing_starts_at' => $period['starts_at'],
                        'listing_expires_at' => $period['ends_at'],
                        // Nagamit na ang term: sa sunod nga renewal, ang admin mo-set na usab
                        'ads_term_months' => null,
                        'ads_ends_on' => null,
                        'declared_permit_expires_at' => null,
                        'requested_term_type' => null,
                        'requested_term_months' => null,
                        'requested_term_ends_on' => null,
                    ]);

                    // Na-deactivate tungod sa expiry: i-activate balik human ma-approve ang renewal
                    if (! $e->is_active && $e->deactivated_reason === 'expired') {
                        $e->update(['is_active' => true, 'deactivated_reason' => null]);
                    }
                }
            }
        });

        $fresh = $establishment->fresh(self::EST_WITH);
        if ($fresh->approval_status === 'approved') {
            $until = $fresh->listing_expires_at ? ' It is live until '.$fresh->listing_expires_at->format('M j, Y').'.' : '';
            Notifier::to($fresh->owner_id,
                $wasRenewal ? 'renewal_approved' : 'registration_approved',
                $wasRenewal ? 'Your renewal is approved!' : 'Your registration is approved!',
                "{$fresh->name} passed the document check and is now visible to customers.{$until} Advertising is free.",
                '/owner/ads');
        }

        return new EstablishmentResource($fresh);
    }

    /**
     * Reject: kinahanglan ug rason.
     * Bag-ong registration: ma-delete awtomatik human sa 2 ka adlaw gawas kung i-ayo ug i-resubmit sa owner.
     * Renewal: dili ma-delete ang establishment; ang owner mag-update balik sa business permit.
     */
    public function rejectEstablishment(Request $request, Establishment $establishment): EstablishmentResource
    {
        $data = $request->validate(['remarks' => ['required', 'string', 'min:5', 'max:500']]);

        $wasRenewal = $establishment->isRenewal(); // gi-approve na kaniadto?

        DB::transaction(function () use ($request, $establishment, $data, $wasRenewal) {
            $e = Establishment::whereKey($establishment->id)->lockForUpdate()->firstOrFail();

            if ($e->approval_status !== 'pending') {
                abort(422, 'This registration is no longer pending.');
            }
            if ($e->approvals()->where('admin_id', $request->user()->id)->exists()) {
                abort(422, 'You already reviewed this registration.');
            }

            $e->approvals()->create([
                'admin_id' => $request->user()->id,
                'decision' => 'rejected',
                'remarks' => $data['remarks'],
            ]);

            if ($wasRenewal) {
                // Renewal: dili ma-delete ang establishment. Magpabilin kini nga approved (expired),
                // ug ang owner mag-update balik sa business permit.
                $e->update([
                    'approval_status' => 'approved',
                    'rejection_reason' => $data['remarks'],
                    'rejected_at' => now(),
                    'ads_term_months' => null,
                    'ads_ends_on' => null,
                ]);
            } else {
                $e->update([
                    'approval_status' => 'rejected',
                    'rejection_reason' => $data['remarks'],
                    'rejected_at' => now(),
                ]);
            }
        });

        $fresh = $establishment->fresh(self::EST_WITH);
        if ($wasRenewal) {
            Notifier::to($fresh->owner_id, 'renewal_rejected', 'Your renewal was rejected',
                "{$fresh->name}: {$data['remarks']} Please update your business permit and send your renewal again.",
                '/owner/ads');
        } else {
            $deleteOn = $fresh->rejectedDeleteAt()?->setTimezone(config('datenest.timezone'))->format('M j, Y g:i A');
            Notifier::to($fresh->owner_id, 'registration_rejected', 'Your registration was rejected',
                "{$fresh->name}: {$data['remarks']} Please fix your details and submit again before {$deleteOn}, or it will be deleted.",
                '/owner/listing');
        }

        return new EstablishmentResource($fresh);
    }
}
