<?php

declare(strict_types=1);

use App\Modules\Executive\Http\Controllers\AlertsController;
use App\Modules\Executive\Http\Controllers\AnalysisController;
use App\Modules\Executive\Http\Controllers\CompareController;
use App\Modules\Executive\Http\Controllers\DailySummaryController;
use App\Modules\Executive\Http\Controllers\ExecutiveReportController;
use App\Modules\Executive\Http\Controllers\HistoryController;
use App\Modules\Executive\Http\Controllers\LinksController;
use App\Modules\Executive\Http\Controllers\OpenController;
use App\Modules\Executive\Http\Controllers\ReportsController;
use App\Modules\Executive\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

/*
 * মালিকের কেন্দ্র — প্রতিটা দরজা `executive.view` চায় (কন্ট্রোলারের মিডলওয়্যারে, আর এখানেও)।
 */
Route::middleware('auth')->prefix('owner')->group(function () {

    Route::get('/', [TodayController::class, 'show'])
        ->name('today')
        ->middleware('can:executive.view');

    // ⭐ প্রতিদিনের সারাংশ — পর্দা আর A4 আড়াআড়ি PDF (মালিক, ৭ নভেম্বর ২০২৬, নমুনা "ok"; [[DailySummary]])
    Route::get('/daily-summary', [DailySummaryController::class, 'show'])
        ->name('daily_summary')
        ->middleware('can:executive.view');

    Route::get('/compare', [CompareController::class, 'show'])
        ->name('compare')
        ->middleware('can:executive.view');

    Route::get('/analysis', [AnalysisController::class, 'show'])
        ->name('analysis')
        ->middleware('can:executive.view');

    Route::get('/reports', [ReportsController::class, 'show'])
        ->name('reports')
        ->middleware('can:executive.view');

    // ⓘ মালিকের কেন্দ্রের নিজের রিপোর্ট — কেন্দ্রীয় রিপোর্টের পাতায়
    Route::get('/reports/{slug}', [ExecutiveReportController::class, 'show'])
        ->name('report.show')
        ->middleware('can:executive.view');

    Route::get('/alerts', [AlertsController::class, 'show'])
        ->name('alerts')
        ->middleware('can:executive.view');

    Route::get('/history', [HistoryController::class, 'show'])
        ->name('history')
        ->middleware('can:executive.view');

    // ⭐ ভাই-কোম্পানির পক্ষ — জোড়া দেওয়া ও তোলা আলাদা চাবিতে
    Route::get('/sister-links', [LinksController::class, 'index'])
        ->name('links')
        ->middleware('can:executive.links.manage');

    Route::post('/sister-links', [LinksController::class, 'store'])
        ->name('links.store')
        ->middleware('can:executive.links.manage');

    Route::delete('/sister-links/{link}', [LinksController::class, 'destroy'])
        ->whereNumber('link')
        ->name('links.destroy')
        ->middleware('can:executive.links.manage');

    Route::post('/refresh', [TodayController::class, 'refresh'])
        ->name('refresh')
        ->middleware('can:executive.view');

    // ⓘ সংখ্যা থেকে উৎসে — কোম্পানি/শাখা বদলে তারপর পাতা
    Route::post('/open', [OpenController::class, 'open'])
        ->name('open')
        ->middleware('can:executive.view');
});

/*
 * ⭐ মাস শেষের হিসাব — হিসাব › বই-এ দিন-খাতার পাশে (মালিক, ১৭ নভেম্বর ২০২৬; [[MonthCloseController]])। ⓘ ঠিকানা হিসাবের ঘরে,
 * কিন্তু `/accounts/reports/{slug}`-এর বাইরে — ওটা যেকোনো নাম ধরে আর হিসাবের রুট আগে বসে।
 */
Route::middleware('auth')->prefix('accounts')->group(function () {
    Route::get('/month-close', [\App\Modules\Executive\Http\Controllers\MonthCloseController::class, 'show'])
        ->name('month_close')
        ->middleware('can:accounts.report.month_close');

    // ⭐ দিন শেষের হিসাব — পর্দা, কাগজ, দিন বন্ধ, আবার খোলা, পপআপের "পরে" (মালিক, ১৬ ও ১৮ নভেম্বর ২০২৬; [[DayClosingController]])।
    // ⓘ হিসাব মডিউল থেকে এখানে, ঠিকানা একই — বিক্রয়, গ্রাহক আর প্রতিদিনের সারাংশ পড়ে (BoundariesTest; ম্যাক, ১৮ নভেম্বর ২০২৬)
    Route::get('/day-closing', [\App\Modules\Executive\Http\Controllers\DayClosingController::class, 'show'])
        ->name('dayclose');
    Route::post('/day-closing/close', [\App\Modules\Executive\Http\Controllers\DayClosingController::class, 'close'])
        ->name('dayclose.close');
    Route::post('/day-closing/reopen', [\App\Modules\Executive\Http\Controllers\DayClosingController::class, 'reopen'])
        ->name('dayclose.reopen');
    Route::post('/day-closing/later', [\App\Modules\Executive\Http\Controllers\DayClosingController::class, 'snooze'])
        ->name('dayclose.snooze');
});
