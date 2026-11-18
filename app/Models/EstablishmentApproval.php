<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Desisyon sa usa ka admin (approved / rejected) sa registration sa establishment. */
class EstablishmentApproval extends Model
{
    protected $fillable = ['establishment_id', 'admin_id', 'decision', 'remarks', 'checks'];

    protected function casts(): array
    {
        return ['checks' => 'array'];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}