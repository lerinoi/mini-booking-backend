<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Slot;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    public function store(StoreBookingRequest $request): JsonResponse
    {
        try {
            // Acquire the SQLite writer lock before reading to avoid a deferred
            // transaction read-to-write upgrade (PHP 8.3 ignores transaction_mode).
            $booking = DB::transaction(function () use ($request) {
                $data = $request->validated();
                DB::update('UPDATE slots SET id = id WHERE id = ?', [$data['slot_id']]);
                $slot = Slot::find($data['slot_id']);
                abort_if(! $slot || $slot->starts_at->lessThanOrEqualTo(now('UTC')), 409, 'Slot has already started.');

                // The partial unique index is the authority for active-booking conflicts.
                return Booking::create($data);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 19
                && str_contains($exception->errorInfo[2] ?? '', 'UNIQUE constraint failed: bookings.slot_id')) {
                return response()->json(['message' => 'Slot is already booked.'], 409);
            }
            if (in_array($exception->errorInfo[1] ?? null, [5, 6], true)) {
                return response()->json(['message' => 'Database is busy. Please retry.'], 503, ['Retry-After' => '1']);
            }
            throw $exception;
        }

        return response()->json(['id' => $booking->id], 201);
    }

    public function destroy(string $id): Response
    {
        $updated = Booking::whereKey($id)->whereNull('cancelled_at')
            ->update(['cancelled_at' => now('UTC')->format('Y-m-d H:i:s')]);
        abort_if($updated === 0, 404);

        return response()->noContent();
    }
}
