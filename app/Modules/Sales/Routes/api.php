<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Sales\Http\Controllers\OrderStandingController;
use App\Modules\Sales\Http\Controllers\QrScanController;
use Illuminate\Support\Facades\Route;

/*
 * বিক্রয়ের ফোন-দরজা — `/api/v1/sales/…` (১ অক্টোবর ২০২৬)।
 *
 * ⓘ মডিউলের নিজের ফাইল, তাই এখানে কেবল `api` মিডলওয়্যার আর `api` উপসর্গ আসে
 * ([[ModuleServiceProvider::registerRoutes()]]) — বাকি পাহারা হাতে, `routes/api.php`-এর
 * ফোন-গ্রুপের হুবহু: টোকেন, `abilities:app` (refresh টোকেনে খোলে না), কোম্পানির প্রসঙ্গ,
 * আর ফোনে বিক্রয় বন্ধ থাকলে ৪০৩ `module_off`। চাবি দেখে কন্ট্রোলার নিজে।
 */
Route::prefix('v1/sales')
    ->middleware([
        'auth:sanctum',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':sales',
    ])
    ->group(function (): void {
        Route::get('/standing/{customer}', [OrderStandingController::class, 'standing'])->name('standing');
        Route::post('/offers', [OrderStandingController::class, 'offers'])->name('offers');

        // ⭐ এক কাগজে এক QR — দেখা, "মাল বেরোল", "ডেলিভারি নিশ্চিত" ([[QrScanController]])
        Route::get('/scan/{token}', [QrScanController::class, 'show'])->middleware('can:sales.delivery.view')->name('scan');
        Route::post('/scan/{token}/gate-out', [QrScanController::class, 'gateOut'])->middleware('can:sales.delivery.update')->name('scan.gate_out');
        Route::post('/scan/{token}/deliver', [QrScanController::class, 'deliver'])->middleware('can:sales.delivery.update')->name('scan.deliver');
    });
