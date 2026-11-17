<?php

namespace App\Http\Controllers;

use App\Models\Slot;
use Illuminate\Http\JsonResponse;

class SlotController extends Controller
{
    public function index(): JsonResponse
    {
        $now = now('UTC');
        $timezone = config('app.timezone');
        $slots = Slot::with('activeBooking:id,slot_id')->orderBy('starts_at')->get();

        return response()->json($slots->map(fn (Slot $slot) => [
            'id' => $slot->id,
            'starts_at' => $slot->starts_at->setTimezone($timezone)->toIso8601String(),
            'ends_at' => $slot->ends_at->setTimezone($timezone)->toIso8601String(),
            'timezone' => $timezone,
            'status' => $slot->starts_at->lessThanOrEqualTo($now) ? 'past' : ($slot->activeBooking ? 'booked' : 'available'),
            'active_booking_id' => $slot->activeBooking?->id,
        ]));
    }
}
