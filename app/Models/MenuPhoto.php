<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuPhoto extends Model
{
    protected $fillable = ['establishment_id', 'url'];

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }
}