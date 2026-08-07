<?php

use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok']));

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
});
