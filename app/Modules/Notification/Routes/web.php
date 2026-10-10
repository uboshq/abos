<?php

declare(strict_types=1);

use App\Modules\Notification\Http\Controllers\NotificationAuditController;
use App\Modules\Notification\Http\Controllers\NotificationCenterController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ বিজ্ঞপ্তি ব্যবস্থাপনার পর্দা — প্রতিটা দরজায় নিজের চাবি (মালিকের স্পেক §১৩)।
 */
Route::middleware('auth')->prefix('notification')->group(function () {
    Route::get('/center', [NotificationCenterController::class, 'index'])
        ->middleware('can:notification.center')->name('center.index');
    Route::get('/center/{event}', [NotificationCenterController::class, 'show'])
        ->whereNumber('event')->middleware('can:notification.center')->name('center.show');
    Route::post('/center/archive', [NotificationCenterController::class, 'archive'])
        ->middleware('can:notification.manage')->name('center.archive');

    Route::get('/audit', [NotificationAuditController::class, 'index'])
        ->middleware('can:notification.audit')->name('audit.index');
});
