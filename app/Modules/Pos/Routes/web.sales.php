<?php

declare(strict_types=1);

use App\Modules\Pos\Http\Controllers\PosController;
use App\Modules\Pos\Http\Controllers\ShiftController;
use Illuminate\Support\Facades\Route;

/*
 * পিওএস-এর রুট — **বিক্রয়ের নামে** (`sales.pos.*`, `sales.shift.*`), ঠিকানাও আগেরটাই (`/sales/pos`, `/sales/shifts`)।
 *
 * ── ⛔ কেন নাম বদলায়নি ────────────────────────────────────────────────
 * ধাপ ২-এ কাউন্টারের কোড বিক্রয় থেকে এখানে এসেছে, কিন্তু ফোনের অ্যাপ, বুকমার্ক আর ছাপা কাগজের লিংক
 * পুরনো নামের উপর দাঁড়িয়ে (মালিকের নিয়ম, docs/cloud-tasks/pos-module.md §০.১)। ⓘ ফাইলের নামটাই
 * (`web.sales.php`) ModuleServiceProvider-কে বলে নামগুলো `sales.` দিয়ে শুরু হবে; controller খোঁজা হয়
 * পিওএস-এর ঘরে ([[ModuleServiceProvider::registerBorrowedRouteNames()]])।
 *
 * ⓘ পাহারা (চাবি `sales.pos`, সুইচ `sales.screen_pos`) controller-এর `middleware()`-এ, আগের মতোই।
 */
Route::middleware('auth')->prefix('sales')->group(function () {

    /*
     * কাউন্টার — সবচেয়ে উপরে, কারণ দিনের সবচেয়ে বেশি ব্যবহৃত পর্দা এটাই।
     */
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::post('/', [PosController::class, 'checkout'])->name('checkout');
        Route::get('/lookup', [PosController::class, 'lookup'])->name('lookup');

        /*
         * কাউন্টারে ধরে রাখা বিল — ক্রেতা টাকা আনতে গেছেন।
         *
         * তোলাটা POST, GET নয়: তোলার সময় বিলটার parked_at মুছে যায়,
         * অর্থাৎ অবস্থা বদলায়। GET হলে ব্রাউজারের prefetch বা কারো
         * পাঠানো লিংকেই বিলটা কাউন্টারের তালিকা থেকে হারিয়ে যেত।
         */
        Route::post('/park', [PosController::class, 'park'])->name('park');

        Route::post('/{invoice}/resume', [PosController::class, 'resume'])
            ->whereNumber('invoice')->name('resume');

        /*
         * কাউন্টার থেকেই ফেরত।
         *
         * খোঁজাটা GET (কিছু বদলায় না), নেওয়াটা POST — মাল গুদামে ফেরে,
         * খাতায় দাখিলা বসে, আর টাকা ড্রয়ার থেকে যেতে পারে।
         */
        Route::get('/bill', [PosController::class, 'bill'])->name('bill');
        Route::post('/return', [PosController::class, 'takeBack'])->name('return');
    });

    /*
     * শিফট — ড্রয়ারটার জন্য কেউ একজন দায়ী।
     *
     * খোলা ও বন্ধ দুইটাই POST: অবস্থা বদলায়, আর টাকার দায় বদলায়।
     * Z-রিপোর্ট GET — ওটা প্রশ্ন, তাই লিংকটা পাঠানো যায়।
     */
    Route::prefix('shifts')->name('shift.')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->name('index');
        Route::post('/', [ShiftController::class, 'open'])->name('open');
        Route::post('/{shift}/close', [ShiftController::class, 'close'])
            ->whereNumber('shift')->name('close');
        Route::get('/{shift}', [ShiftController::class, 'show'])
            ->whereNumber('shift')->name('show');
    });
});
