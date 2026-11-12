<?php

declare(strict_types=1);

use App\Modules\Assistant\Http\Controllers\AskController;
use App\Modules\Assistant\Http\Controllers\AssistantController;
use App\Modules\Assistant\Http\Controllers\DraftController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ কোপাইলট — নিজের পাতা (সারাংশ, নজর) আর খাতা (খোঁজা ও কোপাইলট, কেবল মালিক)।
 */
Route::middleware('auth')->prefix('copilot')->group(function () {
    Route::get('/', [AssistantController::class, 'today'])
        ->name('today')
        ->middleware('can:assistant.use');

    Route::post('/dismiss', [AssistantController::class, 'dismiss'])
        ->name('dismiss')
        ->middleware(['can:assistant.use', 'throttle:30,1']);

    // ⭐ প্রশ্ন (ধাপ ৪) — মডেল থাকলে সে বোঝে, নইলে নিজের শব্দ-তালিকা; একজন দিনে কত প্রশ্ন, সেটিং-এ
    Route::get('/ask', [AskController::class, 'show'])
        ->name('ask')
        ->middleware(['can:assistant.use', 'throttle:30,1']);
    // ⭐ দ্রুত জিজ্ঞাসা — উপরের বারের মগজ (মালিক, ১২ নভেম্বর ২০২৬): পাতা না বদলে JSON-এ উত্তর; ফোনের একই দরজা, একই চাবি, একই খাতা
    Route::post('/quick', [\App\Modules\Assistant\Http\Controllers\CopilotApiController::class, 'ask'])
        ->name('quick')
        ->middleware(['can:assistant.use', 'throttle:30,1']);
    Route::post('/answers/{event}/feedback', [AskController::class, 'feedback'])
        ->whereNumber('event')->name('feedback')
        ->middleware(['can:assistant.use', 'throttle:30,1']);

    // ⭐ খসড়া (ধাপ ৩) — ক্রয় চাহিদার খসড়া আর তাগাদার SMS; পাঠান মানুষ
    Route::post('/draft/reorder', [DraftController::class, 'reorder'])
        ->name('draft.reorder')
        ->middleware(['can:assistant.use', 'can:purchase.requisition.create', 'throttle:6,1']);
    Route::get('/draft/dunning', [DraftController::class, 'dunning'])
        ->name('draft.dunning')
        ->middleware(['can:assistant.use', 'can:customer.view', 'can:credit_control.view']);
    Route::post('/draft/dunning', [DraftController::class, 'sendDunning'])
        ->name('draft.dunning.send')
        ->middleware(['can:assistant.use', 'can:customer.view', 'can:credit_control.view', 'throttle:10,1']);

    Route::get('/audit', [AssistantController::class, 'audit'])
        ->name('audit')
        ->middleware('can:assistant.audit');
});
