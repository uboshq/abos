<?php

declare(strict_types=1);

use App\Modules\Backup\Http\Controllers\BackupController;
use App\Modules\Backup\Http\Controllers\DestinationController;
use Illuminate\Support\Facades\Route;

/**
 * ব্যাকআপের দরজা।
 *
 * ── নামগুলো `backup.*`, `system_admin.backup.*` নয় ────────────────────
 * উপসর্গটা [[ModuleServiceProvider]] বসায়, ফোল্ডারের নাম থেকে
 * (`->name($code.'.')`)। তাই এখানে কেবল শেষ অংশটুকু লেখা হয়।
 *
 * ⚠️ পুরনো নাম ধরে রাখার একটা প্রস্তাব ছিল — [[SystemAdminDashboard]]-এর
 * `route()` ডাক যাতে না ভাঙে। কিন্তু তাতে **মেনুতে নেই এমন পাতাগুলোয়**
 * (restore, verify, DR) সাইডবার ভুল মডিউল জ্বালাত: সক্রিয় মডিউল বাছার
 * শেষ ধাপ রুটের উপসর্গ দেখে। **একটা ৫০০ প্রথম দিনেই ধরা পড়ে; ভুল
 * সাইডবার ছয় মাস চলে।** তাই নাম বদলেছে, আর ড্যাশবোর্ডের ডাকটাও একই
 * পরিবর্তনে।
 *
 * ── `auth` এখানেই, প্রোভাইডারে নয় ────────────────────────────────────
 * প্রোভাইডার কেবল `web` দেয়। তাই প্রতিটা মডিউল নিজের দরজা নিজে বন্ধ
 * করে — আর [[EveryRouteIsGuardedTest]] দেখে কোনোটা খোলা রয়ে গেছে কি না।
 */
Route::middleware('auth')->prefix('backup')->group(function () {

    Route::get('/', [BackupController::class, 'index'])
        ->name('index')
        ->middleware('can:backup.view');

    /*
     * ⚠️ এখনই ব্যাকআপ নেওয়া — POST, কারণ এটা কিছু **বদলায়**।
     *
     * GET হলে ব্রাউজারের prefetch বা কারও bookmark একটা ব্যাকআপ চালু
     * করে দিতে পারত, আর দিনে কয়েকবার অকারণে ডিস্ক ভরত।
     */
    Route::post('/', [BackupController::class, 'store'])
        ->name('store')
        ->middleware('can:backup.run');

    /*
     * ⚠️ নামানো — সবচেয়ে কড়া অনুমতির পেছনে, মালিকের নির্দেশে।
     *
     * এই একটা ফাইল মানে গোটা কোম্পানির ডাটাবেস: প্রতিটা দর, প্রতিটা
     * বেতন, প্রতিটা বকেয়া, আর [[FieldSecurity]] দিয়ে আড়াল করা ঘরগুলোও।
     * অর্থাৎ নামানোর অনুমতি কার্যত **সবকিছু দেখার অনুমতি**।
     *
     * ⓘ তবু বোতামটা লাগবেই: সার্ভার যখন ডেটা সেন্টারে, গ্রাহকের নিজের
     * পেনড্রাইভে কপি নেওয়ার একমাত্র পথ এটাই।
     */
    Route::get('/download/{name}', [BackupController::class, 'download'])
        ->name('download')
        ->middleware('can:backup.download');

    // ── গন্তব্য ───────────────────────────────────────────────────────
    Route::prefix('destinations')->name('destination.')->group(function () {
        Route::get('/', [DestinationController::class, 'index'])
            ->name('index')
            ->middleware('can:backup.configure');

        Route::post('/', [DestinationController::class, 'store'])
            ->name('store')
            ->middleware('can:backup.configure');

        /*
         * "পরীক্ষা করুন" — আর এটা কেবল সুবিধা নয়, নকশার অংশ।
         *
         * **যে গন্তব্য কোনোদিন পরীক্ষা করা হয়নি, সেটা গন্তব্য নয় —
         * একটা আশা।** একটা ভুল পথ, একটা মেয়াদোত্তীর্ণ চাবি বা একটা
         * খোলা পেনড্রাইভ — তিনটাই সেটিংসের পাতায় নিখুঁত দেখায়।
         */
        Route::post('/{destination}/test', [DestinationController::class, 'test'])
            ->name('test')
            ->middleware('can:backup.configure');

        Route::delete('/{destination}', [DestinationController::class, 'destroy'])
            ->name('destroy')
            ->middleware('can:backup.configure');
    });
});
