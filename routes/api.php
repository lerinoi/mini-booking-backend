<?php

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::get('/slots', [\App\Http\Controllers\SlotController::class, 'index']);
Route::post('/bookings', [\App\Http\Controllers\BookingController::class, 'store']);
Route::delete('/bookings/{id}', [\App\Http\Controllers\BookingController::class, 'destroy'])->whereNumber('id');
