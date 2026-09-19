<?php

declare(strict_types=1);

use App\Modules\MasterData\Http\Controllers\ExchangeRateController;
use App\Modules\MasterData\Http\Controllers\LocationController;
use App\Modules\MasterData\Http\Controllers\MasterListController;
use App\Modules\MasterData\Http\Controllers\NumberSeriesController;
use Illuminate\Support\Facades\Route;

/*
 * Master Data-র রুট।
 *
 * ছয়টা সরল তালিকা একটাই কন্ট্রোলারে, কিন্তু প্রতিটার নিজের রুটের নাম
 * (master_data.unit.index, master_data.tax.index …) — কারণ মেনু ও
 * ব্রেডক্রাম্ব নাম ধরেই চলে, আর একটা সাধারণ নাম হলে সব সারি একসাথে
 * সক্রিয় দেখাত।
 */

Route::middleware('auth')->prefix('master-data')->group(function () {

    Route::prefix('locations')->name('location.')->group(function () {
        Route::get('/', [LocationController::class, 'index'])->name('index');

        /*
         * ⭐ একটা স্তরের তালিকা — `/locations/level/point`, ১৯ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ স্তরটা পথের অংশ, `?level=` নয়, আর কারণটা পর্দায় দেখা যায়:
         * টুলবার ঠিকানার প্রতিটা অচেনা ঘরকে একটা **ছাঁকনির চিপ** হিসেবে
         * দেখায়। ⛔ তাই `?level=point` দিলে বাংলা পর্দায় কাঁচা ইংরেজি
         * "point ×" চিপ বসত। ⚠️ টুলবারটা সবার ভাগের কম্পোনেন্ট, আর মানটা
         * অনুবাদ করার উপায় ওতে নেই; স্তরটা তো ছাঁকনিও নয়, ট্যাব।
         *
         * ⓘ `whereIn` — মই-এর বাইরের কিছু এলে সোজা ৪০৪, নিয়ন্ত্রকে পৌঁছায়ই না।
         */
        Route::get('/level/{level}', [LocationController::class, 'index'])
            ->whereIn('level', \App\Modules\MasterData\Models\Location::LADDER)
            ->name('level');
        Route::get('/create', [LocationController::class, 'create'])->name('create');
        Route::post('/', [LocationController::class, 'store'])->name('store');

        // install রুটটা {location} প্যাটার্নের আগে — নাহলে
        // /locations/install-bangladesh কে একটা id ভেবে ৪০৪ দিত
        Route::post('/install-bangladesh', [LocationController::class, 'installBangladesh'])->name('install');

        Route::get('/{location}', [LocationController::class, 'show'])->whereNumber('location')->name('show');
        Route::get('/{location}/edit', [LocationController::class, 'edit'])->whereNumber('location')->name('edit');
        Route::put('/{location}', [LocationController::class, 'update'])->whereNumber('location')->name('update');
        Route::delete('/{location}', [LocationController::class, 'destroy'])->whereNumber('location')->name('destroy');
    });

    Route::get('/number-series', [NumberSeriesController::class, 'index'])->name('series.index');
    Route::put('/number-series/{series}', [NumberSeriesController::class, 'update'])->name('series.update');

    /*
     * মুদ্রার হারের ইতিহাস — সাধারণ তালিকার বাইরের একমাত্র মাস্টার পর্দা।
     *
     * সাধারণ লুপের আগে বসানো, যাতে ঠিকানাটা কোনো দিন {id} প্যাটার্নের
     * পেছনে পড়ে না যায়।
     */
    Route::get('/currencies/{id}/rates', [ExchangeRateController::class, 'index'])
        ->whereNumber('id')->name('currency.rates');
    Route::post('/currencies/{id}/rates', [ExchangeRateController::class, 'store'])
        ->whereNumber('id')->name('currency.rates.store');

    /*
     * ছয়টা তালিকা — একই কন্ট্রোলার, আলাদা ঠিকানা ও আলাদা রুট-নাম।
     *
     * লুপে তৈরি, হাতে ছয়বার নয়: নতুন একটা তালিকা যোগ করতে কন্ট্রোলারের
     * KINDS-এ একটা সারি লিখলেই রুটগুলো নিজে থেকে আসে।
     */
    foreach (MasterListController::kinds() as $slug => $name) {
        Route::prefix($slug)->name($name.'.')->group(function () use ($slug) {
            Route::get('/', [MasterListController::class, 'index'])
                ->defaults('kind', $slug)->name('index');
            Route::get('/create', [MasterListController::class, 'create'])
                ->defaults('kind', $slug)->name('create');
            Route::post('/', [MasterListController::class, 'store'])
                ->defaults('kind', $slug)->name('store');
            Route::post('/install-defaults', [MasterListController::class, 'installDefaults'])
                ->defaults('kind', $slug)->name('install');
            Route::get('/{id}/edit', [MasterListController::class, 'edit'])
                ->defaults('kind', $slug)->whereNumber('id')->name('edit');
            Route::put('/{id}', [MasterListController::class, 'update'])
                ->defaults('kind', $slug)->whereNumber('id')->name('update');
            Route::delete('/{id}', [MasterListController::class, 'destroy'])
                ->defaults('kind', $slug)->whereNumber('id')->name('destroy');
            // ফেরার পথ — নিষ্ক্রিয় করা একমুখী দরজা হতে পারে না
            // (MasterListService::activate-এ কারণ)
            Route::post('/{id}/activate', [MasterListController::class, 'activate'])
                ->defaults('kind', $slug)->whereNumber('id')->name('activate');

            // মোছা — ব্যবহার না হলে সত্যিই, নাহলে নিষ্ক্রিয়
            // (MasterListService::delete-এ পুরো নিয়ম)
            Route::delete('/{id}/purge', [MasterListController::class, 'purge'])
                ->defaults('kind', $slug)->whereNumber('id')->name('purge');
            Route::post('/{id}/default', [MasterListController::class, 'makeDefault'])
                ->defaults('kind', $slug)->whereNumber('id')->name('default');
        });
    }
});
