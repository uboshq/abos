<?php

declare(strict_types=1);

use App\Modules\Finance\Http\Controllers\CapitalController;
use App\Modules\Finance\Http\Controllers\DepositController;
use App\Modules\Finance\Http\Controllers\ExpenseController;
use App\Modules\Finance\Http\Controllers\PlanController;
use App\Modules\Finance\Models\DepositKind;
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

    /*
     * সঞ্চয় ও বিনিয়োগ — এক পর্দা, তিনটা ঠিকানা।
     *
     * ── কেন ইস্যুয়ারটা পথের অংশ, প্রশ্নচিহ্নের পরে নয় ────────────────
     * মেনু কোন সারিটা সক্রিয় তা রুটের প্যারামিটার মিলিয়ে বলে
     * ([[MenuBuilder::paramsMatch()]])। কোয়েরি স্ট্রিং হলে তিনটা সারিই
     * একসাথে সক্রিয় দেখাত, আর ব্যবহারকারী জানত না সে কোথায় আছে।
     */
    /*
     * ── কেন প্রতিটা ঠিকানায় ইস্যুয়ারটা থেকে যায়, রেকর্ডের পাতাতেও ────
     * মেনুর সারিটা সক্রিয় থাকে ইস্যুয়ার মিললে। একটা FD খুলে ভেতরে
     * ঢুকলে যদি প্যারামিটারটা হারিয়ে যেত, বাঁ পাশের মেনুতে "ব্যাংক
     * আমানত" নিভে যেত — আর ব্যবহারকারী জানত না সে কোথায় আছে।
     */
    Route::prefix('deposits')->name('deposit.')->group(function () {
        Route::get('/{issuer}', [DepositController::class, 'index'])
            ->whereIn('issuer', DepositKind::ISSUERS)->name('index');

        Route::post('/{issuer}', [DepositController::class, 'store'])
            ->whereIn('issuer', DepositKind::ISSUERS)->name('store');

        /*
         * একটা জমার নিজের পাতা।
         *
         * ── কেন তালিকার ঘরে কাজগুলো নয় ─────────────────────────────
         * কিস্তি দিতে চাই তারিখ, টাকা আর কোন খাত — তিনটা ঘর। ওগুলো
         * তালিকার শেষ কলামে গুঁজলে কলামটা এত সরু হত যে ছোট পর্দায়
         * একটার ঘাড়ে আরেকটা পড়ত, আর টেবিলের স্ক্রলার প্যানেলটা
         * কেটে দিত — টপ-নেভে ঠিক এই ভুলটাই ধরা পড়েছিল।
         *
         * আর চলাচলের ইতিহাসটাও এখানেই: প্রতিটা সংখ্যা তার ভাউচারে
         * নামায় (নিয়ম ১), আর তালিকার একটা ঘরে ষাটটা কিস্তি ধরত না।
         */
        Route::get('/{issuer}/{deposit}', [DepositController::class, 'show'])
            ->whereIn('issuer', DepositKind::ISSUERS)->whereNumber('deposit')->name('show');

        Route::post('/{issuer}/{deposit}/movement', [DepositController::class, 'movement'])
            ->whereIn('issuer', DepositKind::ISSUERS)->whereNumber('deposit')->name('movement');

        Route::post('/{issuer}/{deposit}/close', [DepositController::class, 'close'])
            ->whereIn('issuer', DepositKind::ISSUERS)->whereNumber('deposit')->name('close');

        /*
         * ভুল এন্ট্রি ফিরিয়ে নেওয়া — `close` থেকে আলাদা পথ।
         *
         * একই পথে রাখলে একটা পতাকা দিয়ে দুইটা আলাদা ঘটনা আলাদা করতে
         * হত, আর অনুমতিও এক হয়ে যেত। ভাঙা রোজকার কাজ; ভুল ফেরানো
         * খাতায় হাত দেওয়া।
         */
        Route::post('/{issuer}/{deposit}/cancel', [DepositController::class, 'cancel'])
            ->whereIn('issuer', DepositKind::ISSUERS)->whereNumber('deposit')->name('cancel');
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
/*
 * নামটা স্পষ্ট, ইচ্ছাকৃতভাবে।
 *
 * নাম না দিলে প্রদানকারীর উপসর্গটাই পুরো নাম হয়ে যেত — `finance.` —
 * আর [[EveryRouteIsGuardedTest]]-এর তালিকায় ওটা এমন একটা এন্ট্রি হত
 * যার মানে পরের জন বুঝত না।
 */
Route::middleware('auth')
    ->get('/accounts/capital', fn () => redirect()->route('finance.capital.index'))
    ->name('capital.moved');
