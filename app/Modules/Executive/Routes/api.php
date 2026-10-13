<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Executive\Http\Controllers\ExecutiveApiController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ মালিকের কেন্দ্রের ফোন-দরজা — `/api/v1/executive/…` (মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu")।
 *
 * ⓘ বিক্রয়ের ফোন-দরজার হুবহু পাহারা ([[Sales/Routes/api.php]]): টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, আর ফোনে মালিকের
 * কেন্দ্র বন্ধ থাকলে ৪০৩ `module_off`। চাবি ওয়েবের "আজ" পাতার একই — `executive.view`। কেবল পড়া।
 */
Route::prefix('v1/executive')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':executive',
    ])
    ->group(function (): void {
        Route::get('/today', [ExecutiveApiController::class, 'today'])->middleware('can:executive.view')->name('today');
    });
