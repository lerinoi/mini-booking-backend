<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model
{
    protected $fillable = ['slot_id', 'customer_name', 'customer_email', 'cancelled_at'];

    protected function casts(): array
    {
        return ['cancelled_at' => \App\Casts\UtcDateTime::class];
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(Slot::class);
    }
}
