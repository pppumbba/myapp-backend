<?php

use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\Market\DanController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['name' => config('app.name')]));

// Sign in with Google: the browser goes to Google and back here (the session keeps the state).
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:login');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:login');

// Verifying people through DAN: the browser comes back here.
Route::get('/auth/dan/callback', [DanController::class, 'callback'])->middleware('throttle:login');
