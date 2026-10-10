<?php

declare(strict_types=1);

use App\Modules\Notification\Http\Controllers\NotificationArchiveController;
use App\Modules\Notification\Http\Controllers\NotificationAuditController;
use App\Modules\Notification\Http\Controllers\NotificationCenterController;
use App\Modules\Notification\Http\Controllers\NotificationChannelController;
use App\Modules\Notification\Http\Controllers\NotificationDeliveryController;
use App\Modules\Notification\Http\Controllers\NotificationEscalationController;
use App\Modules\Notification\Http\Controllers\NotificationGroupController;
use App\Modules\Notification\Http\Controllers\NotificationQuietController;
use App\Modules\Notification\Http\Controllers\NotificationReportController;
use App\Modules\Notification\Http\Controllers\NotificationRuleController;
use App\Modules\Notification\Http\Controllers\NotificationScheduleController;
use App\Modules\Notification\Http\Controllers\NotificationTemplateController;
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

    // ⭐ ধাপ ৩ — নিয়ম: তালিকা, লেখা, সংস্করণ, শুকনো পরীক্ষা
    Route::get('/rules', [NotificationRuleController::class, 'index'])->middleware('can:notification.rules')->name('rules.index');
    Route::get('/rules/create', [NotificationRuleController::class, 'create'])->middleware('can:notification.rules')->name('rules.create');
    Route::post('/rules', [NotificationRuleController::class, 'store'])->middleware('can:notification.rules')->name('rules.store');
    Route::get('/rules/{rule}', [NotificationRuleController::class, 'edit'])
        ->whereNumber('rule')->middleware('can:notification.rules')->name('rules.edit');
    Route::put('/rules/{rule}', [NotificationRuleController::class, 'update'])
        ->whereNumber('rule')->middleware('can:notification.rules')->name('rules.update');
    Route::post('/rules/{rule}/test', [NotificationRuleController::class, 'test'])
        ->whereNumber('rule')->middleware(['can:notification.rules', 'throttle:30,1'])->name('rules.test');

    // ⭐ ধাপ ৩ — টেমপ্লেট স্টুডিও; প্রকাশ আলাদা চাবিতে
    Route::get('/templates', [NotificationTemplateController::class, 'index'])->middleware('can:notification.templates')->name('templates.index');
    Route::get('/templates/create', [NotificationTemplateController::class, 'create'])->middleware('can:notification.templates')->name('templates.create');
    Route::post('/templates', [NotificationTemplateController::class, 'store'])->middleware('can:notification.templates')->name('templates.store');
    Route::get('/templates/{template}', [NotificationTemplateController::class, 'edit'])
        ->whereNumber('template')->middleware('can:notification.templates')->name('templates.edit');
    Route::put('/templates/{template}', [NotificationTemplateController::class, 'update'])
        ->whereNumber('template')->middleware('can:notification.templates')->name('templates.update');
    Route::post('/templates/{template}/publish', [NotificationTemplateController::class, 'publish'])
        ->whereNumber('template')->middleware('can:notification.templates.publish')->name('templates.publish');
    Route::post('/templates/{template}/test', [NotificationTemplateController::class, 'test'])
        ->whereNumber('template')->middleware(['can:notification.templates', 'throttle:6,1'])->name('templates.test');

    // ⭐ ধাপ ৩ — প্রাপক-দল আর সূচি
    Route::get('/groups', [NotificationGroupController::class, 'index'])->middleware('can:notification.recipients')->name('groups.index');
    Route::get('/groups/create', [NotificationGroupController::class, 'create'])->middleware('can:notification.recipients')->name('groups.create');
    Route::post('/groups', [NotificationGroupController::class, 'store'])->middleware('can:notification.recipients')->name('groups.store');
    Route::get('/groups/{group}', [NotificationGroupController::class, 'edit'])
        ->whereNumber('group')->middleware('can:notification.recipients')->name('groups.edit');
    Route::put('/groups/{group}', [NotificationGroupController::class, 'update'])
        ->whereNumber('group')->middleware('can:notification.recipients')->name('groups.update');

    Route::get('/schedules', [NotificationScheduleController::class, 'index'])->middleware('can:notification.schedules')->name('schedules.index');
    Route::get('/schedules/create', [NotificationScheduleController::class, 'create'])->middleware('can:notification.schedules')->name('schedules.create');
    Route::post('/schedules', [NotificationScheduleController::class, 'store'])->middleware('can:notification.schedules')->name('schedules.store');
    Route::get('/schedules/{schedule}', [NotificationScheduleController::class, 'edit'])
        ->whereNumber('schedule')->middleware('can:notification.schedules')->name('schedules.edit');
    Route::put('/schedules/{schedule}', [NotificationScheduleController::class, 'update'])
        ->whereNumber('schedule')->middleware('can:notification.schedules')->name('schedules.update');

    // ⭐ ধাপ ৩ — নীরব সময় আর সারসংক্ষেপ: কোম্পানির স্বাভাবিক নীরব সময়, কে কী বেছেছেন
    Route::get('/quiet', [NotificationQuietController::class, 'index'])->middleware('can:notification.preferences')->name('quiet.index');
    Route::post('/quiet', [NotificationQuietController::class, 'save'])->middleware('can:notification.preferences')->name('quiet.save');

    // ⭐ ধাপ ৪ — ওপরে পাঠানো, আর্কাইভ, ১৭টা রিপোর্ট (রিপোর্টের চাবি নিয়ন্ত্রকে)
    Route::get('/escalations', [NotificationEscalationController::class, 'index'])
        ->middleware('can:notification.escalations')->name('escalations.index');
    Route::get('/archive', [NotificationArchiveController::class, 'index'])
        ->middleware('can:notification.archive')->name('archive.index');
    Route::get('/reports/{slug}', [NotificationReportController::class, 'show'])->name('report.show');
});
