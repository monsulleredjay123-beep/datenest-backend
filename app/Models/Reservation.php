<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    protected $fillable = [
        'customer_id', 'establishment_id', 'date', 'time', 'guests', 'notes',
        'status', 'status_updated_at',
        'amount_due', 'payment_status', 'payment_method', 'payment_ref', 'payment_account', 'paid_at', 'reminded_at',
        'birthdate', 'id_type', 'id_number', 'id_photo_path',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'birthdate' => 'date:Y-m-d',
            'guests' => 'integer',
            'amount_due' => 'float',
            'status_updated_at' => 'datetime',
            'paid_at' => 'datetime',
            'reminded_at' => 'datetime',
            'id_number' => 'encrypted', // naka-encrypt sa database
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }
}