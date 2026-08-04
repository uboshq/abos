<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');

    // rate limit — সেকশন ৮। শেয়ার্ড হোস্টে ব্রুট-ফোর্স সাধারণ ঘটনা,
    // আর ৩ বারের পর ক্যাপচা আসে (সেকশন ১৬.৫); এটা তার উপরের স্তর।
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
