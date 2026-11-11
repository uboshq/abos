<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Assistant\Http\Controllers\CopilotApiController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ ফোনের কোপাইলট (মালিক, ১১ নভেম্বর ২০২৬) — ওয়েবের একই চাবি (`assistant.use`), একই ইঞ্জিন।
 */
Route::prefix('v1/copilot')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':assistant',
        'can:assistant.use',
    ])
    ->group(function (): void {
        Route::get('/today', [CopilotApiController::class, 'today'])->name('today');
        Route::post('/ask', [CopilotApiController::class, 'ask'])->middleware('throttle:30,1')->name('ask');
        Route::post('/answers/{event}/feedback', [CopilotApiController::class, 'feedback'])->whereNumber('event')->middleware('throttle:30,1')->name('feedback');
        Route::post('/dismiss', [CopilotApiController::class, 'dismiss'])->middleware('throttle:30,1')->name('dismiss');
    });
