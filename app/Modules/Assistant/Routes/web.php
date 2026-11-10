<?php

declare(strict_types=1);

use App\Modules\Assistant\Http\Controllers\AssistantController;
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

    Route::get('/audit', [AssistantController::class, 'audit'])
        ->name('audit')
        ->middleware('can:assistant.audit');
});
