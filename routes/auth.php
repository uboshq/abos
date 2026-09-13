<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');

    /*
     * দ্বিতীয় দরজা — একই ফর্ম, শান্ত নকশা।
     *
     * ── কেন `/login`-কে বদলে দেওয়া হয়নি ─────────────────────────────
     * `login` নামটা Laravel-এর নিজের redirect-ও ব্যবহার করে
     * (`redirectGuestsTo`), আর বহু জায়গা থেকে `route('login')` ডাকা
     * হয়। ওটা সরালে পুরো অ্যাপ ধরে খুঁজে বেড়াতে হত, আর একটা বাদ পড়লে
     * সেশন শেষ হওয়া লোকটা একটা ৪০৪-এ গিয়ে পড়তেন।
     *
     * তাই পুরনো দরজাটা যেখানে ছিল সেখানেই, আর নতুনটা পাশে।
     */
    Route::get('/signin', [LoginController::class, 'calm'])->name('login.calm');

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

    /*
     * ⛔ পাসওয়ার্ড ভুলে গেলে ফেরার পথ — ১৩ সেপ্টেম্বর ২০২৬।
     *
     * ── কেন `guest` গোষ্ঠীর ভেতরে ───────────────────────────────────
     * চারটাই এমন মানুষের জন্য যিনি ঢুকতে **পারছেন না**। ⓘ যিনি
     * ইতিমধ্যে ভেতরে, তাঁর এই পথ লাগে না — তাঁর জন্য প্রোফাইলের
     * পাসওয়ার্ড বদল, যেখানে পুরনো পাসওয়ার্ড চাওয়া যায়। `guest`
     * তাঁকে নিজে থেকেই ড্যাশবোর্ডে ফেরত পাঠায়।
     *
     * ── ⚠️ throttle, আর কেন সংখ্যাগুলো এমন ──────────────────────────
     * লগইনের দরজায় `throttle:10,1`। ⛔ রিসেটের দরজা তার চেয়ে **ঢিলা
     * হলে আক্রমণকারী কেবল এই দরজাটাই ব্যবহার করতেন** — দুইটার একটাতে
     * তালা মানে তালা নেই ([[CredentialCheck]]-এ একই কথা লেখা)।
     *
     * ⭐ চিঠি পাঠানোর দরজাটা **আরও কড়া, মিনিটে পাঁচ** — আর সেটা
     * ইচ্ছাকৃত: ওটার প্রতিটা সফল ডাক একটা ইমেইল বাইরে পাঠায়। ⓘ ঢিলা
     * রাখলে ওটা দিয়ে অন্যের ইনবক্সে চিঠি ঢালা যেত (mail bombing), আর
     * সেই সাথে আমাদের পাঠানোর সুনামও পুড়ত। ⚠️ ব্রোকারের নিজের ৬০
     * সেকেন্ডের তালা (`config/auth.php`) এর **উপরের** স্তর, বিকল্প নয়:
     * ওটা ঠিকানা ধরে, এটা IP ধরে।
     *
     * ⓘ টোকেনসহ পাতাটাও (`password.reset`) throttled — ওটা GET, কিন্তু
     * টোকেন আন্দাজ করার চেষ্টাটা ঠিক ওখানেই হত।
     */
    Route::get('/forgot-password', [PasswordResetController::class, 'request'])
        ->name('password.request');

    Route::post('/forgot-password', [PasswordResetController::class, 'email'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:10,1')
        ->name('password.reset');

    Route::post('/reset-password', [PasswordResetController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('password.store');
});

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
