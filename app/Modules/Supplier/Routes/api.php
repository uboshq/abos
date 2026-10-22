<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Supplier\Http\Controllers\PrincipalCommissionApiController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ সরবরাহকারীর ফোন-দরজা — `/api/v1/supplier/…` (মালিক, fe-র মারফত, ২০ অক্টোবর ২০২৬: প্রিন্সিপালের কমিশন *"app e dekhate hobe"*)।
 *
 * ⓘ কেবল পড়া। পাহারা ক্রয়ের ফোন-দরজার হুবহু ([[Purchase/Routes/api.php]]): টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, আর ফোনে
 * ক্রয় বন্ধ থাকলে ৪০৩ `module_off` — ফোনের মডিউল-সুইচে "সরবরাহকারী" আলাদা নেই, প্রিন্সিপাল ক্রয়েরই অংশ।
 * ⛔ চাবি রুটেই — ওয়েবের রিপোর্টের একই `supplier.report` ([[SupplierReportController]])।
 */
Route::prefix('v1/supplier')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':purchase',
    ])
    ->group(function (): void {
        Route::get('/principal-commission', PrincipalCommissionApiController::class)
            ->middleware('can:supplier.report')
            ->name('principal_commission');
    });
