<?php

declare(strict_types=1);

use App\Modules\Notification\Http\Controllers\NotificationAuditController;
use App\Modules\Notification\Http\Controllers\NotificationCenterController;
use App\Modules\Notification\Http\Controllers\NotificationChannelController;
use App\Modules\Notification\Http\Controllers\NotificationDeliveryController;
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

    // ⭐ ধাপ ২ — মাধ্যম: সাজানো, VAPID চাবি, সংযোগ পরীক্ষা
    $channel = 'email|web_push|mobile_push|sms';
    Route::get('/channels', [NotificationChannelController::class, 'index'])
        ->middleware('can:notification.channels')->name('channels.index');
    Route::post('/channels/web_push/vapid', [NotificationChannelController::class, 'vapid'])
        ->middleware('can:notification.channels')->name('channels.vapid');
    Route::get('/channels/{channel}', [NotificationChannelController::class, 'edit'])
        ->where('channel', $channel)->middleware('can:notification.channels')->name('channels.edit');
    Route::put('/channels/{channel}', [NotificationChannelController::class, 'update'])
        ->where('channel', $channel)->middleware('can:notification.channels')->name('channels.update');
    Route::post('/channels/{channel}/test', [NotificationChannelController::class, 'test'])
        ->where('channel', $channel)->middleware(['can:notification.channels', 'throttle:6,1'])->name('channels.test');

    // ⭐ ধাপ ২ — ডেলিভারি: কিউ, লগ, ব্যর্থ-তালিকা, স্বাস্থ্য; হাতে আবার চেষ্টা আর বাতিল আলাদা চাবিতে
    Route::get('/deliveries/queue', [NotificationDeliveryController::class, 'queue'])
        ->middleware('can:notification.deliveries')->name('deliveries.queue');
    Route::get('/deliveries/logs', [NotificationDeliveryController::class, 'logs'])
        ->middleware('can:notification.deliveries')->name('deliveries.logs');
    Route::get('/deliveries/failed', [NotificationDeliveryController::class, 'failed'])
        ->middleware('can:notification.deliveries')->name('deliveries.failed');
    Route::get('/deliveries/health', [NotificationDeliveryController::class, 'health'])
        ->middleware('can:notification.deliveries')->name('deliveries.health');
    Route::post('/deliveries/{job}/retry', [NotificationDeliveryController::class, 'retry'])
        ->whereNumber('job')->middleware('can:notification.retry')->name('deliveries.retry');
    Route::post('/deliveries/{job}/cancel', [NotificationDeliveryController::class, 'cancel'])
        ->whereNumber('job')->middleware('can:notification.retry')->name('deliveries.cancel');
});
