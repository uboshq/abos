<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\CapitalController;
use App\Modules\Finance\Http\Controllers\ExpenseController;
use App\Modules\Finance\Http\Controllers\PlanController;
use Illuminate\Support\Facades\Route;

/*
 * অর্থের পর্দাগুলো।
 *
 * ── কেন `finance/` উপসর্গ, `accounts/` নয় ────────────────────────────
 * ঠিকানাটাই বলে দেয় জিনিসটা কার। কেউ `accounts/capital` বুকমার্ক করে
 * রাখলে সে নতুন জায়গায় পৌঁছায় — নিচের পুনর্নির্দেশটা সেজন্যই, আর
 * সেটা মডিউল ভাগ করার সময়কার একটা নিয়ম: পুরনো ঠিকানা ভাঙে না।
 */
/*
 * `web` ও `finance.` উপসর্গ দুইটাই প্রদানকারী বসায়
 * ([[ModuleServiceProvider::registerRoutes()]])। এখানে আবার লিখলে
 * নামটা `finance.finance.capital.index` হয়ে যেত — প্রথম চেষ্টায় ঠিক
 * তাই হয়েছিল, আর রুটের তালিকাতেই ধরা পড়ল।
 */
Route::middleware('auth')->prefix('finance')->group(function () {
    Route::get('/plan', [PlanController::class, 'index'])->name('plan');

    /*
     * খরচ — কোন খাতে কত গেল।
     *
     * লেখার পথ নেই: খরচ লেখা হয় ভাউচারেই। দুইটা পথ থাকলে দুইটার
     * যাচাই একদিন আলাদা হয়ে যেত।
     */
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expense.index');

    /*
     * মূলধন ও বিনিয়োগ।
     *
     * `post` আলাদা একটা POST, কারণ ওটাই আসল ঘটনা: লিখে রাখা নিরীহ,
     * পোস্ট করা মানে খাতায় টাকা বসে যাওয়া।
     */
    Route::prefix('capital')->name('capital.')->group(function () {
        Route::get('/', [CapitalController::class, 'index'])->name('index');
        Route::post('/', [CapitalController::class, 'store'])->name('store');
        Route::post('/{entry}/post', [CapitalController::class, 'post'])
            ->whereNumber('entry')->name('post');
    });
});

/*
 * পুরনো ঠিকানা — একদিনের জন্য মূলধন `accounts/capital`-এ ছিল।
 *
 * ── কেন পুনর্নির্দেশ, আর কেন স্থায়ী নয় ──────────────────────────────
 * ওই ঠিকানাটা মাত্র কয়েক ঘণ্টা লাইভে ছিল, তবু কেউ বুকমার্ক করে
 * থাকতে পারেন। ৩০২ (স্থায়ী নয়), কারণ ঠিকানাটা ভুল ছিল না — কেবল
 * মডিউল ভাগ হওয়ায় সরেছে, আর ব্রাউজারের ক্যাশে চিরকাল বসিয়ে রাখার
 * মতো কিছু নয়।
 */
Route::middleware('auth')->get('/accounts/capital', fn () => redirect()->route('finance.capital.index'));
