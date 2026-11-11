<?php

use App\Http\Controllers\BookingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api.headers', 'auth:sanctum'])->group(function () {
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::post('/bookings', [BookingController::class, 'store'])->middleware('throttle:60,1');
    Route::delete('/bookings/{id}', [BookingController::class, 'destroy'])->whereNumber('id');
    Route::get('/rooms/{room}/bookings/today', [BookingController::class, 'today']);
});
