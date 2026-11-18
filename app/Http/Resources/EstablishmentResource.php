<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EstablishmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $price = $this->price !== null ? (float) $this->price : null;
        // Owner ra ang makakita sa iyang permit, logo, ug sa detalye sa bayad
        $ownerView = $request->user() && (int) $request->user()->id === (int) $this->owner_id;

        return [
            'id' => $this->id,
            'owner_id' => $this->owner_id,
            'name' => $this->name,
            'category' => $this->category,
            'is_private' => (bool) $this->is_private,
            'description' => $this->description,
            'address' => $this->address,
            'area' => $this->area,
            'lat' => (float) $this->lat,
            'lng' => (float) $this->lng,
            'opening_time' => substr((string) $this->opening_time, 0, 5),
            'closing_time' => substr((string) $this->closing_time, 0, 5),
            'avg_rating' => (float) $this->avg_rating,
            'price' => $price,
            // Public place: walay reservation, mao walay fee
            'reservation_fee' => $this->is_private && $this->reservation_fee !== null ? (float) $this->reservation_fee : null,
            // false = diretso na (public place o reservation fee nga 0): walay reservation, direction ra
            'accepts_reservations' => $this->acceptsReservations(),
            'preferences' => $this->preferences->pluck('name')->values(),
            'amenities' => $this->amenities->pluck('name')->values(),
            'photos' => $this->photos->pluck('url')->values(),
            // Logo sa owner (gi-load sa search / details ra)
            'logo' => $this->whenLoaded('owner', fn () => $this->owner?->logo),
            // Owner ra (iyang kaugalingong listing): business permit ug logo, para ma-usab human ma-reject.
            // Dili makita sa customers o sa admin list (sa admin, naay review endpoint).
            'requirements' => $this->when($ownerView, fn () => [
                'business_permit' => $this->owner?->business_permit,
                'logo' => $this->owner?->logo,
            ]),
            // Owner ra: libre ang ads; kung pwede na mag-renew (human ma-expire ang contract)
            'ads' => $this->when($ownerView, fn () => [
                'can_renew' => $this->renewBlockReason() === null,
                'block_reason' => $this->renewBlockReason(),
            ]),
            // Owner ug admin ra: ang term nga gi-request sa owner (para ma-double check sa admin)
            'requested' => $this->when($ownerView || $request->user()?->role === 'admin', fn () => $this->declared_permit_expires_at ? [
                'permit_expires_at' => $this->declared_permit_expires_at->format('Y-m-d'),
                'term_type' => $this->requested_term_type,
                'term_months' => $this->requested_term_months,
                'term_ends_on' => $this->requested_term_ends_on?->format('Y-m-d'),
            ] : null),
            // Mga picture sa menu. Gi-load sa owner ug sa details page ra (dili sa search)
            'menu_photos' => $this->whenLoaded('menuPhotos', fn () => $this->menuPhotos->pluck('url')->values(), []),
            'is_active' => (bool) $this->is_active,
            // Admin ra: ngalan sa owner
            'owner_name' => $this->when($request->user()?->role === 'admin', fn () => $this->owner?->name),
            'owner_email' => $this->when($request->user()?->role === 'admin', fn () => $this->owner?->email),
            'deactivated_reason' => $this->deactivated_reason,
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'permit_expires_at' => $this->permit_expires_at?->format('Y-m-d'),
            // Approval: pending -> approved (naa na sa Establishments) o rejected (ma-delete human sa 2 ka adlaw)
            'approval_status' => $this->approval_status,
            // true = renewal (gi-approve na kaniadto, karon gi-review balik tungod sa bag-ong business permit)
            'is_renewal' => $this->isRenewal(),
            'rejection_reason' => $this->rejection_reason,
            'rejected_at' => $this->rejected_at?->toIso8601String(),
            'delete_at' => $this->rejectedDeleteAt()?->toIso8601String(),
            'listing_starts_at' => $this->listing_starts_at?->format('Y-m-d'),
            'listing_expires_at' => $this->listing_expires_at?->format('Y-m-d'),
            'is_listed' => $this->hasActiveListing(),
            'approval' => $this->whenLoaded('approvals', fn () => $this->approvalSummary($request)),
            'contracts' => $this->whenLoaded('contracts', fn () => $this->contracts->take(10)->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'duration_months' => $c->duration_months,
                'status' => $c->status,
                'starts_at' => $c->starts_at?->format('Y-m-d'),
                'ends_at' => $c->ends_at?->format('Y-m-d'),
                'remarks' => $c->remarks,
            ])->values()),
            'price_flag' => $price === null ? 'Price not provided' : null,
            // gikan sa withCount() sa search; 0 kung dili search
            'matched' => (int) ($this->resource->getAttribute('matched') ?? 0),
        ];
    }

    /** Owner: ihap ra. Admin: kauban ang ngalan sa mga nag-review ug kung nag-boto na ba siya. */
    private function approvalSummary(Request $request): array
    {
        $isAdmin = $request->user()?->role === 'admin';
        $summary = [
            'required' => (int) config('datenest.required_approvals', 2),
            'approved' => $this->approvals->where('decision', 'approved')->count(),
        ];

        if ($isAdmin) {
            $summary['my_decision'] = $this->approvals->firstWhere('admin_id', $request->user()->id)?->decision;
            $summary['votes'] = $this->approvals->map(fn ($a) => [
                'admin' => $a->relationLoaded('admin') ? $a->admin?->name : null,
                'decision' => $a->decision,
                'remarks' => $a->remarks,
                'at' => $a->created_at?->toIso8601String(),
            ])->values();
        }

        return $summary;
    }
}