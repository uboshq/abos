<?php

declare(strict_types=1);

use App\Modules\Executive\Http\Controllers\AlertsController;
use App\Modules\Executive\Http\Controllers\CompareController;
use App\Modules\Executive\Http\Controllers\HistoryController;
use App\Modules\Executive\Http\Controllers\OpenController;
use App\Modules\Executive\Http\Controllers\TodayController;
use Illuminate\Support\Facades\Route;

/*
 * মালিকের কেন্দ্র — প্রতিটা দরজা `executive.view` চায় (কন্ট্রোলারের মিডলওয়্যারে, আর এখানেও)।
 */
Route::middleware('auth')->prefix('owner')->group(function () {

    Route::get('/', [TodayController::class, 'show'])
        ->name('today')
        ->middleware('can:executive.view');

    Route::get('/compare', [CompareController::class, 'show'])
        ->name('compare')
        ->middleware('can:executive.view');

    Route::get('/alerts', [AlertsController::class, 'show'])
        ->name('alerts')
        ->middleware('can:executive.view');

    Route::get('/history', [HistoryController::class, 'show'])
        ->name('history')
        ->middleware('can:executive.view');

    Route::post('/refresh', [TodayController::class, 'refresh'])
        ->name('refresh')
        ->middleware('can:executive.view');

    // ⓘ সংখ্যা থেকে উৎসে — কোম্পানি/শাখা বদলে তারপর পাতা
    Route::post('/open', [OpenController::class, 'open'])
        ->name('open')
        ->middleware('can:executive.view');
});
