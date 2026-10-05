<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Sales\Http\Controllers\DepositRequestController;
use App\Modules\Sales\Http\Controllers\OrderStandingController;
use App\Modules\Sales\Http\Controllers\QrScanController;
use App\Modules\Sales\Http\Controllers\SaleTrackingController;
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

        // ⭐ স্লিপসহ জমার অনুরোধ — SR দোকানির ব্যাংক স্লিপের ছবি পাঠান ([[DepositRequestController]])
        Route::get('/deposit-requests/accounts', [DepositRequestController::class, 'accounts'])->name('deposit_request.accounts');
        Route::get('/deposit-requests', [DepositRequestController::class, 'apiIndex'])->name('deposit_request.index');
        Route::post('/deposit-requests', [DepositRequestController::class, 'apiStore'])->name('deposit_request.store');

        // ⭐ ডেলিভারি ট্র্যাকিং — বিক্রি কোথায়, কে কখন ([[SaleTrackingController]]); চাবি দুইয়ের যেকোনো একটা, পদ্ধতিতে
        Route::get('/tracking', [SaleTrackingController::class, 'index'])->name('tracking.index');
        Route::get('/tracking/{kind}/{id}', [SaleTrackingController::class, 'show'])
            ->where('kind', 'challan|order')->whereUuid('id')->name('tracking.show');

        // ⭐ ডেলিভারি অর্ডার — লেখা, জমা, সুপারভাইজারের পরিমাণ ([[DeliveryOrderApiController]], ২ অক্টোবর ২০২৬)
        // ⭐ ফোনের কাউন্টার — ওয়েবের একই যাচাই আর একই দরজা ([[DirectSaleApiController]], ৪ অক্টোবর ২০২৬); টাকা আছে, কেবল অনলাইনে
        Route::get('/direct/setup', [\App\Modules\Sales\Http\Controllers\DirectSaleApiController::class, 'setup'])->name('direct.setup');
        Route::get('/direct/free-allowed', [\App\Modules\Sales\Http\Controllers\DirectSaleApiController::class, 'freeAllowed'])->name('direct.free_allowed');
        Route::post('/direct/overview', [\App\Modules\Sales\Http\Controllers\DirectSaleApiController::class, 'overview'])->name('direct.overview');
        Route::post('/direct', [\App\Modules\Sales\Http\Controllers\DirectSaleApiController::class, 'store'])->name('direct.store');
        // ⭐ কাউন্টারের বাকি বোতাম — খসড়া খোলা আর কারণসহ বাতিল, ওয়েবের একই সেবা (মালিক, ৪ অক্টোবর ২০২৬)
        Route::get('/direct/drafts', [\App\Modules\Sales\Http\Controllers\DirectSaleApiController::class, 'drafts'])->name('direct.drafts');
        Route::get('/direct/drafts/{id}', [\App\Modules\Sales\Http\Controllers\DirectSaleApiController::class, 'draft'])->whereUuid('id')->name('direct.draft');
        Route::post('/direct/void', [\App\Modules\Sales\Http\Controllers\DirectSaleApiController::class, 'void'])->name('direct.void');
        // ⭐ ফোনে বিক্রি ফেরত — ওয়েবের একই যাচাই, সেবা আর সারাংশ ([[SalesReturnApiController]], ৪ অক্টোবর ২০২৬)
        Route::get('/returns/setup', [\App\Modules\Sales\Http\Controllers\SalesReturnApiController::class, 'setup'])->name('return.setup');
        Route::get('/returns/invoice/{id}', [\App\Modules\Sales\Http\Controllers\SalesReturnApiController::class, 'invoice'])->whereUuid('id')->name('return.invoice');
        Route::post('/returns', [\App\Modules\Sales\Http\Controllers\SalesReturnApiController::class, 'store'])->name('return.store');
        Route::get('/returns/{id}/overview', [\App\Modules\Sales\Http\Controllers\SalesReturnApiController::class, 'overview'])->whereUuid('id')->name('return.overview');
        Route::post('/returns/{id}/confirm', [\App\Modules\Sales\Http\Controllers\SalesReturnApiController::class, 'confirm'])->whereUuid('id')->name('return.confirm');

        Route::get('/delivery-orders', [\App\Modules\Sales\Http\Controllers\DeliveryOrderApiController::class, 'index'])->name('delivery_order.index');
        Route::post('/delivery-orders', [\App\Modules\Sales\Http\Controllers\DeliveryOrderApiController::class, 'store'])->name('delivery_order.store');
        Route::get('/delivery-orders/{id}', [\App\Modules\Sales\Http\Controllers\DeliveryOrderApiController::class, 'show'])->whereUuid('id')->name('delivery_order.show');
        Route::put('/delivery-orders/{id}', [\App\Modules\Sales\Http\Controllers\DeliveryOrderApiController::class, 'update'])->whereUuid('id')->name('delivery_order.update');
        Route::post('/delivery-orders/{id}/submit', [\App\Modules\Sales\Http\Controllers\DeliveryOrderApiController::class, 'submit'])->whereUuid('id')->name('delivery_order.submit');
        // ⓘ চাবি ছকের — এখনকার স্তরের অনুমোদনকারী ([[DeliveryOrderService::setApprovedQuantities()]])
        Route::post('/delivery-orders/{id}/approved-quantities', [\App\Modules\Sales\Http\Controllers\DeliveryOrderApiController::class, 'approvedQuantities'])->whereUuid('id')->name('delivery_order.approved_quantities');
    });
