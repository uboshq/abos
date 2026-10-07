<?php

declare(strict_types=1);

use App\Modules\Restaurant\Http\Controllers\KitchenBoardController;
use App\Modules\Restaurant\Http\Controllers\ProductionController;
use App\Modules\Restaurant\Http\Controllers\RecipeController;
use App\Modules\Restaurant\Http\Controllers\RestaurantReportController;
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

    /*
     * রেসিপি — কোন খাবার কী দিয়ে তৈরি। মজুদ থেকে এখানে আনা,
     * ১৫ সেপ্টেম্বর ২০২৬, মালিকের সিদ্ধান্তে।
     *
     * ⭐ রান্নার ঠিক আগে, আর ক্রমটা ইচ্ছাকৃত: রেসিপি একটা **নিয়ম**
     * (বছরে দুইবার বদলায়), রান্না একটা **ঘটনা** (রোজ সকালে ঘটে)।
     * নিয়ম আগে, ঘটনা পরে।
     *
     * `whereNumber` — নাহলে `/recipes/create`-কে একটা id ভেবে
     * বাইন্ডিং ৪০৪ দিত।
     */
    Route::prefix('recipes')->name('recipe.')->group(function () {
        Route::get('/', [RecipeController::class, 'index'])->name('index');
        Route::get('/create', [RecipeController::class, 'create'])->name('create');
        Route::post('/', [RecipeController::class, 'store'])->name('store');
        Route::get('/{recipe}/edit', [RecipeController::class, 'edit'])
            ->whereNumber('recipe')->name('edit');
        Route::put('/{recipe}', [RecipeController::class, 'update'])
            ->whereNumber('recipe')->name('update');
        Route::delete('/{recipe}', [RecipeController::class, 'destroy'])
            ->whereNumber('recipe')->name('destroy');
        Route::post('/{recipe}/activate', [RecipeController::class, 'activate'])
            ->whereNumber('recipe')->name('activate');
    });

    /*
     * রান্না — হাঁড়ির উৎপাদন। মজুদ থেকে এখানে আনা, ১৫ সেপ্টেম্বর ২০২৬।
     *
     * `confirm` আলাদা একটা POST, কারণ ওটাই আসল ঘটনা: খসড়া লেখা নিরীহ,
     * নিশ্চিত করা মানে গুদাম থেকে মাল বেরিয়ে যাওয়া।
     *
     * ⚠️ URL-এর অংশটা `cookings`-ই রাখা হলো, মজুদে যা ছিল হুবহু তাই —
     * কেবল উপসর্গ বদলেছে (`/inventory/cookings` → `/restaurant/cookings`)।
     * ⓘ পুরনো বুকমার্ক তবু ভাঙবে, আর সেটা এড়ানোর উপায় নেই: রুটের নাম
     * ও ঠিকানা দুইটাই মডিউল থেকে আসে।
     */
    Route::prefix('cookings')->name('production.')->group(function () {
        Route::get('/', [ProductionController::class, 'index'])->name('index');
        Route::get('/create', [ProductionController::class, 'create'])->name('create');
        Route::post('/', [ProductionController::class, 'store'])->name('store');
        Route::get('/{production}', [ProductionController::class, 'show'])
            ->whereNumber('production')->name('show');
        Route::post('/{production}/confirm', [ProductionController::class, 'confirm'])
            ->whereNumber('production')->name('confirm');
        Route::delete('/{production}', [ProductionController::class, 'destroy'])
            ->whereNumber('production')->name('destroy');
    });

    /*
     * খাদ্য-খরচের রিপোর্ট — এটাও মালিকের দাগানো, একই দিনে।
     *
     * ⓘ ঠিকানাটা প্রতিটা মডিউলের নিজের (`/reports/{slug}`), তাই রিপোর্টটা
     * সরানো মানে রেস্তোরাঁর নিজের একটা রিপোর্ট-দরজা লাগে।
     */
    Route::get('/reports/{slug}', [RestaurantReportController::class, 'show'])->name('report.show');
});
