<?php

declare(strict_types=1);

use App\Modules\Restaurant\Http\Controllers\KitchenBoardController;
use Illuminate\Support\Facades\Route;

/*
 * রেস্টুরেন্ট মডিউলের রুট — ModuleServiceProvider নিজে নিবন্ধন করে
 * (সেকশন ১৯.৩)। নামের উপসর্গ "restaurant." আপনাআপনি বসে।
 *
 * ── আজ কেবল রান্নাঘর ─────────────────────────────────────────────────
 * বাকি উনিশটা পর্দা `module.php`-তে `planned => true` হিসেবে ঘোষিত,
 * তাই তাদের কোনো রুট নেই — আর সেটাই ঠিক। রুট বানিয়ে রেখে দিলে
 * `EveryRouteIsGuardedTest`-কে উনিশটা ফাঁকা দরজার জন্য ছাড় লিখতে হত,
 * আর একদিন কেউ ওই ছাড়গুলো দেখে ভাবতেন পর্দাগুলো আছে।
 */
Route::middleware('auth')->prefix('restaurant')->group(function () {

    /*
     * রান্নাঘরের বোর্ড ও টিকিট — মজুদ থেকে এখানে আনা, কোড অপরিবর্তিত।
     *
     * `advance` একটাই ঠিকানা, গন্তব্যটা অনুরোধে আসে: চারটা অবস্থার
     * জন্য চারটা রুট বানালে ধাপের নিয়মটা রুটের তালিকায় ছড়িয়ে যেত,
     * আর ওটা এক জায়গায় থাকা দরকার।
     */
    Route::prefix('kitchen')->name('kitchen.')->group(function () {
        Route::get('/', [KitchenBoardController::class, 'index'])->name('index');
        Route::get('/refresh', [KitchenBoardController::class, 'refresh'])->name('refresh');

        Route::get('/tickets', [KitchenBoardController::class, 'tickets'])->name('tickets');
        Route::get('/tickets/feed', [KitchenBoardController::class, 'ticketFeed'])->name('feed');

        Route::post('/tickets/{ticket}/advance', [KitchenBoardController::class, 'advance'])
            ->whereNumber('ticket')->name('advance');
    });
});
