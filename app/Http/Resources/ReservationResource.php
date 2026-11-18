<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        // Ang ID makita ra sa owner sa establishment (dili sa customer o admin)
        $isOwner = $user
            && $this->relationLoaded('establishment')
            && $this->establishment
            && $this->establishment->owner_id === $user->id;

        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'customer_name' => $this->customer?->name ?? '',
            'establishment_id' => $this->establishment_id,
            'establishment_name' => $this->establishment?->name ?? '',
            'date' => $this->date->format('Y-m-d'),
            'time' => substr((string) $this->time, 0, 5),
            'guests' => (int) $this->guests,
            'notes' => $this->notes ?? '',
            'status' => $this->status,
            'status_updated_at' => $this->status_updated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'review' => $this->review ? new ReviewResource($this->review) : null,
            'guest_id' => $isOwner ? $this->guestId() : null,
            'amount_due' => (float) $this->amount_due,
            'payment_status' => $this->payment_status,
            'payment_method' => $this->payment_method,
            'payment_ref' => $this->payment_ref,
            'payment_account' => $this->payment_account,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }

    private function guestId(): ?array
    {
        if (! $this->birthdate) {
            return null;
        }

        $photo = '';
        $disk = Storage::disk('local'); // private (dili public)

        if ($this->id_photo_path && $disk->exists($this->id_photo_path)) {
            $mime = $disk->mimeType($this->id_photo_path) ?: 'image/jpeg';
            $photo = 'data:'.$mime.';base64,'.base64_encode($disk->get($this->id_photo_path));
        }

        return [
            'birthdate' => $this->birthdate->format('Y-m-d'),
            'id_type' => (string) $this->id_type,
            'id_number' => (string) $this->id_number,
            'id_photo' => $photo,
        ];
    }
}