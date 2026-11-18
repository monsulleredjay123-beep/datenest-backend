<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Advertising contract sa establishment: 'initial' (sa registration) o 'renewal'. */
class EstablishmentContract extends Model
{
    protected $fillable = [
        'establishment_id', 'type', 'duration_months', 'amount', 'payment_method', 'payment_ref', 'payment_account', 'paid_at',
        'status', 'starts_at', 'ends_at', 'remarks', 'decided_by', 'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'duration_months' => 'integer',
            'amount' => 'float',
            'paid_at' => 'datetime',
            'starts_at' => 'date:Y-m-d',
            'ends_at' => 'date:Y-m-d',
            'decided_at' => 'datetime',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}