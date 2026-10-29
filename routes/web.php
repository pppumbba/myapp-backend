<?php

use App\Http\Controllers\Api\V1\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Sign in with Google: the browser goes to Google and back here (the session keeps the state).
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:login');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:login');
