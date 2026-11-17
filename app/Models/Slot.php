<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Slot extends Model
{
    protected $fillable = ['starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => \App\Casts\UtcDateTime::class, 'ends_at' => \App\Casts\UtcDateTime::class];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function activeBooking(): HasOne
    {
        return $this->hasOne(Booking::class)->whereNull('cancelled_at');
    }
}
