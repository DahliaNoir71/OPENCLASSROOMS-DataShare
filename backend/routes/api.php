<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok']));

// Open to everyone, hence the strict limiter: these two routes are the ones a
// credential stuffing run would hammer.
Route::prefix('auth')->middleware('throttle:auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);
});

// Behind a token, so the general api limiter (60/min, keyed by user id) is the
// right ceiling: 5/min per IP would break a normal session, and several users
// behind one NAT would throttle each other.
Route::prefix('auth')->middleware('auth:api')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
