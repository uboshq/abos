<?php

declare(strict_types=1);

use App\Modules\Customer\Http\Controllers\ConductController;
use App\Modules\Customer\Http\Controllers\CustomerController;
use App\Modules\Customer\Http\Controllers\CustomerPortalController;
use App\Modules\Customer\Http\Controllers\CustomerReportController;
use Illuminate\Support\Facades\Route;

/*
 * মডিউলের নিজের রুট — ModuleServiceProvider নিজে থেকে নিবন্ধন করে
 * (সেকশন ১৯.৩), তাই routes/web.php-তে এই ফাইলের কোনো উল্লেখ নেই।
 *
 * নামের উপসর্গ "customer." আপনাআপনি বসে, কারণ প্রোভাইডার মডিউলের code
 * দিয়ে name() ঠিক করে দেয়। module.php-তে ঘোষিত রুটের নামগুলো তাই
 * এখানকার নামের সাথে মেলে।
 */

Route::middleware('auth')->prefix('customers')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->name('index');
    Route::get('/create', [CustomerController::class, 'create'])->name('create');
    Route::post('/', [CustomerController::class, 'store'])->name('store');

    /*
     * রিপোর্টের রুট {customer}-এর আগে, আর {customer} সংখ্যায় বাঁধা।
     *
     * দুইটাই দরকার: আগে না রাখলে /customers/reports/ageing কে একটা id
     * ভেবে রুট-মডেল বাইন্ডিং ৪০৪ দিত, আর whereNumber ছাড়া ভবিষ্যতে
     * যোগ হওয়া যেকোনো স্থির পথ একই ফাঁদে পড়ত।
     */
    Route::get('/reports/{slug}', [CustomerReportController::class, 'show'])->name('report.show');

    /*
     * আচরণ নামানো — কোড দিয়ে, তাই {customer}-এর আগে ও স্থির পথে।
     * "conduct" সংখ্যা নয় বলে {customer} (whereNumber) এটাকে গিলত না,
     * তবু স্পষ্টতার জন্য উপরে।
     */
    Route::post('/conduct/{conduct}/retire', [ConductController::class, 'retire'])
        ->whereNumber('conduct')->name('conduct.retire');

    Route::get('/{customer}', [CustomerController::class, 'show'])
        ->whereNumber('customer')->name('show');
    Route::get('/{customer}/edit', [CustomerController::class, 'edit'])
        ->whereNumber('customer')->name('edit');
    Route::put('/{customer}', [CustomerController::class, 'update'])
        ->whereNumber('customer')->name('update');
    Route::delete('/{customer}', [CustomerController::class, 'destroy'])
        ->whereNumber('customer')->name('destroy');

    /*
     * ফিরিয়ে আনা — নিষ্ক্রিয় করার একই অনুমতিতে।
     *
     * এতদিন শুধু নিষ্ক্রিয় করা যেত, ফেরার পথ ছিল না। ফলে ভুল করে বন্ধ
     * করা গ্রাহকের জন্য কেউ দ্বিতীয় রেকর্ড খুলত, আর তখন একই দোকানের
     * দুইটা আলাদা বকেয়া — যা মেলানোর আর কোনো উপায় থাকত না।
     */
    Route::post('/{customer}/activate', [CustomerController::class, 'activate'])
        ->whereNumber('customer')->name('activate');

    // পার্টির আচরণ লেখা — গ্রাহকের পাতা থেকে
    Route::post('/{customer}/conduct', [ConductController::class, 'store'])
        ->whereNumber('customer')->name('conduct.store');

    /*
     * পোর্টালের চাবি — গ্রাহকের নিজের রুট নয়, আলাদা কন্ট্রোলারে।
     *
     * ── কেন `update`-এর সাথে জোড়া নয় ───────────────────────────────
     * বাকি রুটগুলো `customer.update` অনুমতিতে চলে। এই দুইটা চলে
     * `customer.portal`-এ, কারণ কাজটা আলাদা: এতে বাইরের একজন মানুষ
     * ইন্টারনেট থেকে ভেতরের সংখ্যা দেখার অধিকার পান। এক অনুমতিতে
     * রাখলে ডাটা এন্ট্রির লোকও চাবি বিলি করতে পারতেন।
     */
    Route::post('/{customer}/portal', [CustomerPortalController::class, 'store'])
        ->whereNumber('customer')->name('portal.store');
    Route::delete('/{customer}/portal', [CustomerPortalController::class, 'destroy'])
        ->whereNumber('customer')->name('portal.destroy');
});
