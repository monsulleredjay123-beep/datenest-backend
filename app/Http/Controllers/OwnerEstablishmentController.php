<?php

namespace App\Http\Controllers;

use App\Http\Resources\EstablishmentResource;
use App\Models\Amenity;
use App\Models\Establishment;
use App\Models\Preference;
use App\Models\User;
use App\Services\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OwnerEstablishmentController extends Controller
{
    private const WITH = ['preferences', 'amenities', 'photos', 'menuPhotos', 'contracts', 'approvals'];

    /** Ang listing sa naka-login nga owner */
    public function index(Request $request): AnonymousResourceCollection
    {
        // safety net kung wala naka-run ang scheduler
        Establishment::deactivateExpired();
        Establishment::purgeRejected();

        $list = Establishment::where('owner_id', $request->user()->id)
            ->with(self::WITH)
            ->orderBy('id')
            ->get();

        return EstablishmentResource::collection($list);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        if (Establishment::where('owner_id', $request->user()->id)->exists()) {
            abort(422, 'You already have a listing. Please edit it instead.');
        }

        // Kinahanglan naay business permit ug logo (gikan sa register o i-upload karon)
        $this->assertRequirements($request->user(), $data);

        // Ang gusto nga term sa owner (nakasunod sa expiry sa permit), para ma-double check sa admin
        $term = $request->validate($this->termRules(), $this->termMessages());
        $this->assertTermFitsPermit($term);

        $establishment = DB::transaction(function () use ($data, $term, $request) {
            $this->saveRequirements($request->user(), $data);

            $e = Establishment::create($this->columns($data) + $this->termColumns($term) + [
                'owner_id' => $request->user()->id,
                'is_active' => true,
                'avg_rating' => 0,
                'approval_status' => 'pending', // i-check una sa admin ang mga document
                'submitted_at' => now(),
            ]);
            $this->syncRelations($e, $data);

            return $e;
        });

        // Notification: sa admin (naay bag-ong registration) ug sa owner (na-receive na)
        $waiting = Establishment::where('approval_status', 'pending')->count();
        $permit = $request->user()->business_permit ? '' : ' (No business permit uploaded.)';
        Notifier::admins(
            'registration_submitted',
            'New establishment registration',
            "{$establishment->name} by {$request->user()->name} is waiting for review. Registrations to review: {$waiting}.{$permit}",
            '/admin/registrations',
        );
        Notifier::to($request->user(), 'registration_received', 'Registration received',
            "We received the registration of {$establishment->name}. The admin will check your documents.", '/owner/listing');

        return (new EstablishmentResource($establishment->load(self::WITH)))
            ->response()
            ->setStatusCode(201);
    }

    public function update(Request $request, Establishment $establishment): EstablishmentResource
    {
        Gate::authorize('update', $establishment); // owner ra

        $data = $this->validated($request);
        $user = $request->user();
        $status = $establishment->approval_status;
        $wasRejected = $status === 'rejected';
        $locked = $status === 'approved';
        $term = null;

        if ($locked) {
            // Naka-approve na: gi-check na sa admin ang permit ug logo, dili na pwede usbon dinhi
            $permitChanged = ! empty($data['business_permit']) && $data['business_permit'] !== $user->business_permit;
            $logoChanged = ! empty($data['logo']) && $data['logo'] !== $user->logo;
            if ($permitChanged || $logoChanged) {
                abort(422, 'Your business permit and logo were already approved, so they can no longer be changed here.');
            }
        } else {
            // Pending o rejected: kinahanglan naay business permit ug logo
            $this->assertRequirements($user, $data);

            // ... ug ang gusto nga term (nakasunod sa expiry sa permit)
            $term = $request->validate($this->termRules(), $this->termMessages());
            $this->assertTermFitsPermit($term);
        }

        $changed = [];

        DB::transaction(function () use ($establishment, $data, $term, $user, $locked, $status, &$changed) {
            $establishment->update($this->columns($data));
            $this->syncRelations($establishment, $data);

            if (! $locked) {
                $changed = $this->saveRequirements($user, $data);
                $establishment->update($this->termColumns($term));

                // Gi-usab ang permit/logo samtang pending: dili na maapil ang daan nga boto sa admin
                if ($status === 'pending' && $changed) {
                    $establishment->approvals()->delete();
                    $establishment->update(['details_verified_at' => null]);
                }
            }

            // Na-reject kaniadto: i-resubmit para ma-review balik (limpyo ang daan nga boto)
            if ($establishment->approval_status === 'rejected') {
                $establishment->approvals()->delete();
                $establishment->update([
                    'approval_status' => 'pending',
                    'rejection_reason' => null,
                    'rejected_at' => null, // dili na ma-delete
                    'submitted_at' => now(),
                ]);
            }
        });

        $updated = $changed ? ' Updated: '.implode(' and ', array_map(fn ($k) => $k === 'business_permit' ? 'business permit' : 'logo', $changed)).'.' : '';

        if ($wasRejected) {
            Notifier::admins('registration_resubmitted', 'Registration submitted again',
                "{$establishment->name} was fixed and submitted again by {$user->name}.{$updated}", '/admin/registrations');
            Notifier::to($user, 'registration_resubmitted', 'Registration submitted again',
                "We received the updated registration of {$establishment->name}. The admin will check it again.", '/owner/listing');
        } elseif ($changed) {
            Notifier::admins('registration_updated', 'Registration documents updated',
                "{$establishment->name} ({$user->name}) changed documents while under review.{$updated}", '/admin/registrations');
        }

        return new EstablishmentResource($establishment->fresh(self::WITH));
    }

    /**
     * Owner: renewal human ma-expire ang contract. Kinahanglan i-update ang business permit (bag-ong picture)
     * ug i-request ang gusto nga term (nakadepende sa expiry sa permit).
     * Moadto kini sa admin para ma-review: ang admin mobasa sa expiry sa permit, mo-double check, ug mo-set sa
     * katapusan nga term (bulan/tuig o exact nga petsa). Pagkahuman ma-approve, ang owner na ang mobayad.
     */
    public function requestRenewal(Request $request, Establishment $establishment): EstablishmentResource
    {
        Gate::authorize('update', $establishment); // owner ra

        $data = $request->validate([
            'business_permit' => ['required', 'string', 'max:3000000', 'regex:/^data:image\/(jpeg|png|webp);base64,/i'],
        ] + $this->termRules(), [
            'business_permit.required' => 'Please upload your updated business permit.',
            'business_permit.regex' => 'The business permit must be an image (JPG, PNG or WEBP).',
            'business_permit.max' => 'The business permit image is too large. Please upload a smaller one.',
        ] + $this->termMessages());

        // Ang gusto nga term kinahanglan nga dili molapas sa expiry sa permit nga gi-declare sa owner
        $this->assertTermFitsPermit($data);

        $user = $request->user();

        if ($reason = $establishment->renewBlockReason()) {
            abort(422, $reason);
        }
        // Kinahanglan gyud bag-ong permit (dili ang parehas nga picture)
        if ($data['business_permit'] === $user->business_permit) {
            throw ValidationException::withMessages([
                'business_permit' => 'This is the same business permit you already submitted. Please upload your updated business permit.',
            ]);
        }

        DB::transaction(function () use ($establishment, $user, $data) {
            $user->update(['business_permit' => $data['business_permit']]);

            $establishment->approvals()->delete(); // limpyo ang daan nga boto para ma-review balik
            $establishment->update([
                'approval_status' => 'pending',
                'rejection_reason' => null,
                'rejected_at' => null,
                'submitted_at' => now(),
                'details_verified_at' => null,
                'permit_expires_at' => null, // ang admin mobasa sa bag-ong expiry
                'ads_term_months' => null,
                'ads_ends_on' => null,
            ] + $this->termColumns($data) + [
                // Dili makita sa customers samtang ginareview ang renewal
                'is_active' => $establishment->listing_expires_at ? false : $establishment->is_active,
                'deactivated_reason' => $establishment->listing_expires_at ? 'expired' : $establishment->deactivated_reason,
            ]);
        });

        Notifier::admins('renewal_requested', 'Renewal request',
            "{$establishment->name} ({$user->name}) sent an updated business permit for renewal. Please review it and set the advertising term.",
            '/admin/registrations');
        Notifier::to($user, 'renewal_received', 'Renewal request received',
            "We received your updated business permit for {$establishment->name}. The admin will review it and set your new advertising term. Advertising is free.",
            '/owner/ads');

        return new EstablishmentResource($establishment->fresh(self::WITH));
    }

    /**
     * Owner: i-request o usbon ang gusto nga term (pila ka bulan/tuig o exact nga petsa) samtang gi-review pa
     * ang registration o renewal. Nakadepende kini sa expiry sa business permit, ug ang admin mo-double check
     * kung tinuod ba (ang admin ang mo-set sa katapusan nga term).
     */
    public function setTermRequest(Request $request, Establishment $establishment): EstablishmentResource
    {
        Gate::authorize('update', $establishment); // owner ra

        $data = $request->validate($this->termRules(), $this->termMessages());
        $this->assertTermFitsPermit($data);

        if ($establishment->approval_status !== 'pending') {
            abort(422, 'You can set your requested term while your registration or renewal is under review.');
        }

        $establishment->update($this->termColumns($data));

        Notifier::admins('term_requested', 'Requested term updated',
            "{$establishment->name} requested {$this->termText($data)} (business permit expires ".Carbon::parse($data['declared_permit_expires_at'])->format('M j, Y').'). Please double-check it against the permit.',
            '/admin/registrations');

        return new EstablishmentResource($establishment->fresh(self::WITH));
    }

    /** Rules sa gi-request nga term sa owner: expiry sa permit + pila ka bulan O exact nga petsa */
    private function termRules(): array
    {
        $min = (int) config('datenest.contract_min_months', 1);
        $max = (int) config('datenest.contract_max_months', 60);

        return [
            'declared_permit_expires_at' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'requested_term_type' => ['required', Rule::in(['months', 'date'])],
            'requested_term_months' => ['required_if:requested_term_type,months', 'nullable', 'integer', "min:{$min}", "max:{$max}"],
            'requested_term_ends_on' => ['required_if:requested_term_type,date', 'nullable', 'date_format:Y-m-d', 'after:today'],
        ];
    }

    private function termMessages(): array
    {
        $min = (int) config('datenest.contract_min_months', 1);
        $max = (int) config('datenest.contract_max_months', 60);

        return [
            'declared_permit_expires_at.required' => 'Please enter the expiry date written on your business permit.',
            'declared_permit_expires_at.after_or_equal' => 'Your business permit is already expired. Please use a valid business permit.',
            'requested_term_type.required' => 'Please choose your advertising term.',
            'requested_term_months.required_if' => 'Please enter how many months.',
            'requested_term_months.integer' => 'The number of months must be a whole number.',
            'requested_term_months.min' => "The term must be at least {$min} month.",
            'requested_term_months.max' => "The term cannot be more than {$max} months.",
            'requested_term_ends_on.required_if' => 'Please choose the exact end date.',
            'requested_term_ends_on.after' => 'The end date must be after today.',
        ];
    }

    /** Logic: ang gi-request nga term dili pwede molapas sa expiry sa business permit */
    private function assertTermFitsPermit(array $data): void
    {
        $max = (int) config('datenest.contract_max_months', 60);
        $permitEnd = Carbon::parse($data['declared_permit_expires_at'])->startOfDay();

        if ($data['requested_term_type'] === 'months') {
            $months = (int) $data['requested_term_months'];
            $end = today()->addMonthsNoOverflow($months)->subDay();
            if ($end->gt($permitEnd)) {
                throw ValidationException::withMessages([
                    'requested_term_months' => "A {$months}-month term ends on {$end->format('M j, Y')}, which is after your business permit expires ({$permitEnd->format('M j, Y')}). Choose a shorter term or the exact date.",
                ]);
            }

            return;
        }

        $end = Carbon::parse($data['requested_term_ends_on'])->startOfDay();
        if ($end->gt($permitEnd)) {
            throw ValidationException::withMessages([
                'requested_term_ends_on' => "The end date cannot be after your business permit expires ({$permitEnd->format('M j, Y')}).",
            ]);
        }
        if ($end->gt(today()->addMonthsNoOverflow($max))) {
            throw ValidationException::withMessages([
                'requested_term_ends_on' => "The end date cannot be more than {$max} months from today.",
            ]);
        }
    }

    /** Mga column sa gi-request nga term (isa ra ang naay sulod: months o date) */
    private function termColumns(array $data): array
    {
        $months = $data['requested_term_type'] === 'months';

        return [
            'declared_permit_expires_at' => $data['declared_permit_expires_at'],
            'requested_term_type' => $data['requested_term_type'],
            'requested_term_months' => $months ? (int) $data['requested_term_months'] : null,
            'requested_term_ends_on' => $months ? null : $data['requested_term_ends_on'],
        ];
    }

    private function termText(array $data): string
    {
        return $data['requested_term_type'] === 'months'
            ? "{$data['requested_term_months']} month(s)"
            : 'a term until '.Carbon::parse($data['requested_term_ends_on'])->format('M j, Y');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(Establishment::OWNER_CATEGORIES)],
            'description' => ['required', 'string', 'min:10', 'max:2000'],
            'address' => ['required', 'string', 'max:255'],
            // Featured area sa landing page (optional)
            'area' => ['nullable', Rule::in(array_keys(Establishment::AREAS))],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'reservation_fee' => ['nullable', 'numeric', 'min:0'],
            'preferences' => ['nullable', 'array'],
            'preferences.*' => ['string', 'exists:preferences,name'],
            // Dynamic: ang owner pwede mag-add ug bag-ong amenity (mo-create kung wala pa)
            'amenities' => ['nullable', 'array', 'max:30'],
            'amenities.*' => ['string', 'min:2', 'max:50', 'regex:/^[\pL\pN][\pL\pN\s&\'’\-\/().,+]*$/u'],
            'photos' => ['nullable', 'array', 'max:10'],
            // http(s) link O base64 data URL (gikan sa upload sa owner)
            'photos.*' => [
                'string',
                'max:2000000',
                'regex:/^(https?:\/\/|data:image\/(jpeg|png|webp);base64,)/i',
            ],
            // Mga picture sa menu (parehas nga format sa photos)
            'menu_photos' => ['nullable', 'array', 'max:8'],
            'menu_photos.*' => [
                'string',
                'max:2000000',
                'regex:/^(https?:\/\/|data:image\/(jpeg|png|webp);base64,)/i',
            ],
            // Requirements sa owner (image data URL). Mausab ra samtang pending o rejected.
            'business_permit' => ['nullable', 'string', 'max:3000000', 'regex:/^data:image\/(jpeg|png|webp);base64,/i'],
            'logo' => ['nullable', 'string', 'max:400000', 'regex:/^data:image\/(jpeg|png|webp);base64,/i'],
        ], [
            'business_permit.regex' => 'The business permit must be an image (JPG, PNG or WEBP).',
            'business_permit.max' => 'The business permit image is too large. Please upload a smaller one.',
            'logo.regex' => 'The logo must be an image (JPG, PNG or WEBP).',
            'logo.max' => 'The logo image is too large. Please upload a smaller one.',
        ]);
    }

    /** Kinahanglan naay business permit ug logo: bag-ong upload o ang naa na sa account. */
    private function assertRequirements(User $user, array $data): void
    {
        $errors = [];
        if (empty($data['business_permit']) && ! $user->business_permit) {
            $errors['business_permit'] = 'Please upload your business permit.';
        }
        if (empty($data['logo']) && ! $user->logo) {
            $errors['logo'] = 'Please upload your establishment logo.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** I-save sa account sa owner ang bag-ong permit/logo. Return: ang mga field nga nausab. */
    private function saveRequirements(User $user, array $data): array
    {
        $changes = [];
        if (! empty($data['business_permit']) && $data['business_permit'] !== $user->business_permit) {
            $changes['business_permit'] = $data['business_permit'];
        }
        if (! empty($data['logo']) && $data['logo'] !== $user->logo) {
            $changes['logo'] = $data['logo'];
        }

        if ($changes) {
            $user->update($changes);
        }

        return array_keys($changes);
    }

    private function columns(array $data): array
    {
        // Ang owner listing kanunay private (naay reservation). Ang public places naa sa Public Place account.
        $isPrivate = true;

        return [
            'name' => trim($data['name']),
            'category' => $data['category'],
            'is_private' => $isPrivate,
            'description' => trim($data['description']),
            'address' => trim($data['address']),
            'area' => $data['area'] ?? null,
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'opening_time' => $data['opening_time'],
            'closing_time' => $data['closing_time'],
            'price' => $data['price'] ?? null,
            'reservation_fee' => $data['reservation_fee'] ?? null,
        ];
    }

    private function syncRelations(Establishment $e, array $data): void
    {
        $e->preferences()->sync(Preference::whereIn('name', $data['preferences'] ?? [])->pluck('id'));
        $e->amenities()->sync($this->resolveAmenityIds($data['amenities'] ?? []));

        $e->photos()->delete();
        foreach (array_values(array_unique($data['photos'] ?? [])) as $url) {
            $e->photos()->create(['url' => $url]);
        }

        $e->menuPhotos()->delete();
        foreach (array_values(array_unique($data['menu_photos'] ?? [])) as $url) {
            $e->menuPhotos()->create(['url' => $url]);
        }
    }

    /**
     * Amenity names -> ids. Kung wala pa sa database, i-create (case-insensitive, walay doble).
     */
    private function resolveAmenityIds(array $names): array
    {
        $ids = [];
        foreach ($names as $raw) {
            $name = (string) Str::of($raw)->squish();
            if ($name === '') {
                continue;
            }
            $amenity = Amenity::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first()
                ?? Amenity::create(['name' => $name]);
            $ids[$amenity->id] = $amenity->id;
        }

        return array_values($ids);
    }
}