<?php

use App\Http\Controllers\Api\V1\GoogleAuthController;
use App\Http\Controllers\Api\V1\Market\DanController;
use App\Http\Controllers\Api\V1\Market\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Sign in with Google: the browser goes to Google and back here (the session keeps the state).
Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->middleware('throttle:login');
Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->middleware('throttle:login');

// Verifying people through DAN: the browser comes back here; the fake page stands in for DAN until it is connected.
Route::get('/auth/dan/callback', [DanController::class, 'callback'])->middleware('throttle:login');
Route::get('/auth/dan/fake', [DanController::class, 'fake']);
Route::get('/market/pay/fake/{payment}', [PaymentController::class, 'fake'])->whereNumber('payment');
Route::get('/market/pay/fake/{payment}/pay', [PaymentController::class, 'fakePay'])->whereNumber('payment');
