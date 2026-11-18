<?php

namespace App\Models;

use App\Services\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Establishment extends Model
{
    public const CATEGORIES = ['cafe', 'restaurant', 'inn', 'lodge', 'destination'];

    /** Mga category nga pwede pilion sa owner. Ang 'destination' (public place) ang admin ra ang mo-add (Public Places). */
    public const OWNER_CATEGORIES = ['cafe', 'restaurant', 'inn', 'lodge'];

    /** Featured areas sa landing page: key => label. Dugangi diri kung naay bag-ong area. */
    public const AREAS = ['rotary' => 'Rotary, Tagum City'];

    /** Kinahanglan ug ID ug minimum nga edad */
    public const ID_REQUIRED_CATEGORIES = ['inn', 'lodge'];
    public const MIN_STAY_AGE = 18;

    protected $fillable = [
        'owner_id', 'name', 'category', 'description', 'address', 'area', 'lat', 'lng',
        'is_private', 'opening_time', 'closing_time', 'price', 'reservation_fee', 'is_active', 'deactivated_reason', 'avg_rating',
        'approval_status', 'rejection_reason', 'approved_at', 'listing_starts_at', 'listing_expires_at',
        'submitted_at', 'permit_expires_at', 'details_verified_at', 'rejected_at', 'expiry_reminded_for',
        'ads_term_months', 'ads_ends_on',
        'declared_permit_expires_at', 'requested_term_type', 'requested_term_months', 'requested_term_ends_on',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'price' => 'float',
            'reservation_fee' => 'float',
            'avg_rating' => 'float',
            'is_active' => 'boolean',
            'is_private' => 'boolean',
            'approved_at' => 'datetime',
            'listing_starts_at' => 'date:Y-m-d',
            'listing_expires_at' => 'date:Y-m-d',
            'submitted_at' => 'datetime',
            'permit_expires_at' => 'date:Y-m-d',
            'details_verified_at' => 'datetime',
            'rejected_at' => 'datetime',
            'expiry_reminded_for' => 'date:Y-m-d',
            'ads_term_months' => 'integer',
            'ads_ends_on' => 'date:Y-m-d',
            'declared_permit_expires_at' => 'date:Y-m-d',
            'requested_term_months' => 'integer',
            'requested_term_ends_on' => 'date:Y-m-d',
        ];
    }

    /**
     * Makita sa customers: active, naka-approve, ug (para sa private nga establishment) wala pa mo-expire ang contract.
     * Ang public place (park, beach, etc.) walay contract, mao nga makita kini basta active ug approved.
     */
    public function scopeListed($query)
    {
        return $query->where('is_active', true)
            ->where('approval_status', 'approved')
            ->where(fn ($q) => $q->where('is_private', false)
                ->orWhereDate('listing_expires_at', '>=', today()));
    }

    /**
     * I-deactivate (awtomatik) ang tanang approved nga establishment nga na-expire na ang contract.
     * Ang gi-deactivate sa admin mano-mano dili usbon. Return: pila ang na-deactivate.
     */
    public static function deactivateExpired(): int
    {
        $expired = static::query()
            ->where('is_active', true)
            ->where('approval_status', 'approved')
            ->where('is_private', true) // ang public place walay contract
            ->whereDate('listing_expires_at', '<', today())
            ->get();

        foreach ($expired as $e) {
            $e->update(['is_active' => false, 'deactivated_reason' => 'expired']);

            Notifier::to($e->owner_id, 'ads_expired', 'Your advertising contract has expired',
                "{$e->name} is now hidden from customers. To renew, update your business permit so the admin can set your new term.", '/owner/ads');
        }

        return $expired->count();
    }

    /**
     * I-delete ang mga na-reject nga registration nga lapas na sa retention (default 2 ka adlaw).
     * Return: pila ang na-delete. (Ang cascade sa database mo-delete sa photos, approvals, ug uban pa.)
     */
    public static function purgeRejected(): int
    {
        $cutoff = now()->subDays((int) config('datenest.rejected_retention_days', 2));
        $count = 0;

        static::query()
            ->where('approval_status', 'rejected')
            ->whereNotNull('rejected_at')
            ->where('rejected_at', '<=', $cutoff)
            ->get()
            ->each(function (Establishment $e) use (&$count) {
                Notifier::to($e->owner_id, 'registration_deleted', 'Your rejected registration was deleted',
                    "The registration of {$e->name} was deleted because it was not fixed in time. You can register your establishment again anytime.",
                    '/owner/listing');
                $e->delete();
                $count++;
            });

        return $count;
    }

    /** Kanus-a ma-delete ang na-reject nga registration kung dili i-ayo ug i-resubmit */
    public function rejectedDeleteAt(): ?Carbon
    {
        return $this->approval_status === 'rejected' && $this->rejected_at
            ? $this->rejected_at->copy()->addDays((int) config('datenest.rejected_retention_days', 2))
            : null;
    }

    /** Gi-approve na kaniadto (naay approved_at): ang bag-ong review niini kay renewal na */
    public function isRenewal(): bool
    {
        return $this->approved_at !== null;
    }

    /** Naay contract nga wala pa mo-expire */
    public function contractRunning(): bool
    {
        return $this->listing_expires_at !== null && $this->listing_expires_at->gte(today());
    }

    /** Naka-set na ang term gikan sa admin (pila ka bulan O exact nga petsa) */
    public function adsTermSet(): bool
    {
        return $this->ads_term_months !== null || $this->ads_ends_on !== null;
    }

    /**
     * Ang period sa advertising contract: ang term nga gi-set sa admin, magsugod karon, ug dili molapas
     * sa expiry sa business permit. LIBRE ni (walay bayad). Return null kung wala pa term/permit o kung lapas na ang petsa.
     */
    public function adsPeriod(): ?array
    {
        if (! $this->adsTermSet() || ! $this->permit_expires_at) {
            return null;
        }

        $start = today();
        $end = $this->ads_ends_on
            ? $this->ads_ends_on->copy()
            : $start->copy()->addMonthsNoOverflow((int) $this->ads_term_months)->subDay();

        // Dili molapas sa business permit
        if ($end->gt($this->permit_expires_at)) {
            $end = $this->permit_expires_at->copy();
        }
        if ($end->lt($start)) {
            return null;
        }

        // Pila ka bulan ang contract (ang partial nga bulan i-round up)
        $stop = $end->copy()->addDay();
        $months = 0;
        while ($start->copy()->addMonthsNoOverflow($months + 1)->lte($stop)) {
            $months++;
        }
        if ($start->copy()->addMonthsNoOverflow($months)->lt($stop)) {
            $months++;
        }

        return [
            'starts_at' => $start->format('Y-m-d'),
            'ends_at' => $end->format('Y-m-d'),
            'months' => max(1, $months),
        ];
    }

    /** Ngano dili pa pwede mag-renew (null = pwede na). Ang renewal kinahanglan human ma-expire ang contract. */
    public function renewBlockReason(): ?string
    {
        if (! $this->is_private) {
            return 'Public places do not need a contract.';
        }
        if ($this->approval_status !== 'approved') {
            return 'Your registration must be approved by the admin first.';
        }
        if (! $this->is_active && $this->deactivated_reason !== 'expired') {
            return 'Your listing was deactivated by the administrator. Please contact the admin.';
        }
        if ($this->contractRunning()) {
            return 'Your ads are running until '.$this->listing_expires_at->format('M j, Y').'. You can renew after it expires.';
        }

        return null;
    }

    public function hasActiveListing(): bool
    {
        if (! $this->is_active || $this->approval_status !== 'approved') {
            return false;
        }

        // Public place: walay contract
        return ! $this->is_private || $this->contractRunning();
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(EstablishmentApproval::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(EstablishmentContract::class)->orderByDesc('id');
    }

    public function requiresId(): bool
    {
        return in_array($this->category, self::ID_REQUIRED_CATEGORIES, true);
    }

    /**
     * Ang reservation naa ra kung private ang lugar ug naay reservation fee (labaw sa 0).
     * Kung ang fee kay 0 o blangko, walay reservation (walk-in): diretso na, parehas sa public place.
     */
    public function acceptsReservations(): bool
    {
        return (bool) $this->is_private
            && $this->reservation_fee !== null
            && (float) $this->reservation_fee > 0;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function preferences(): BelongsToMany
    {
        return $this->belongsToMany(Preference::class, 'establishment_preference');
    }

    public function amenities(): BelongsToMany
    {
        return $this->belongsToMany(Amenity::class, 'establishment_amenity');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class);
    }

    /** Una nga photo (para sa landing page, dili ma-load ang tanan nga base64) */
    public function firstPhoto(): HasOne
    {
        return $this->hasOne(Photo::class)->oldestOfMany();
    }

    public function menus(): HasMany
    {
        return $this->hasMany(Menu::class)->orderBy('id');
    }

    public function menuPhotos(): HasMany
    {
        return $this->hasMany(MenuPhoto::class)->orderBy('id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}