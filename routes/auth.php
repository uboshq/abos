<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');

    /*
     * rate limit — সেকশন ৮। শেয়ার্ড হোস্টে ব্রুট-ফোর্স সাধারণ ঘটনা।
     *
     * ── এখানে আগে যা লেখা ছিল, আর কেন সরানো হলো (৩১ আগস্ট ২০২৬) ──────
     * লেখা ছিল "৩ বারের পর ক্যাপচা আসে (সেকশন ১৬.৫); এটা তার উপরের
     * স্তর"। পুরো কোডবেসে **"captcha" শব্দটার একটাও হদিস নেই** —
     * পরিকল্পনায় ছিল, বানানো হয়নি।
     *
     * মন্তব্যটা তাই একটা মিথ্যা আশ্বাস ছিল: পরের জন পড়ে ধরে নিতেন
     * ব্রুট-ফোর্স দুই স্তরে ঢাকা, অথচ স্তর একটাই। **নেই জিনিসের কথা
     * লেখা থাকা না-লেখার চেয়ে খারাপ**, কারণ তখন কেউ আর খোঁজে না।
     *
     * ── আজ সত্যিই যা আছে ────────────────────────────────────────────
     * এই একটাই স্তর: IP ধরে মিনিটে দশবার। অ্যাকাউন্ট ধরে কোনো লক নেই,
     * তাই একটাই পাসওয়ার্ড বহু IP থেকে চেষ্টা করলে এটা ধরে না।
     * প্রতিটা চেষ্টা `login_history`-তে লেখা থাকে (LoginJournal), তাই
     * ঘটনার পর দেখা যায় — কিন্তু ঘটার সময় থামানো যায় না।
     */
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
