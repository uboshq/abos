<?php

declare(strict_types=1);

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
