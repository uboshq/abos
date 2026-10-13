<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Promotion\Http\Controllers\PromotionApiController;
use Illuminate\Support\Facades\Route;

/*
 * প্রচারের ফোন-দরজা — `/api/v1/promotion/…` (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬): SR-এর হাতে চলতি অফার, কেবল পড়া।
 *
 * ⓘ বিক্রয়ের ফোন-দরজার হুবহু পাহারা ([[Sales/Routes/api.php]]); ফোনে প্রচার বন্ধ থাকলে ৪০৩ `module_off`।
 * চাবি কন্ট্রোলারে ([[PromotionApiController::middleware()]])।
 */
Route::prefix('v1/promotion')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':promotion',
    ])
    ->group(function (): void {
        Route::get('/offers', [PromotionApiController::class, 'offers'])->name('phone.offers');
    });
