<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\RemembersAPhoneWrite;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Inventory\Http\Controllers\QualityPhoneController;
use App\Modules\Inventory\Http\Controllers\SerialPhoneController;
use App\Modules\Inventory\Http\Controllers\StockPhoneController;
use Illuminate\Support\Facades\Route;

/*
 * মজুদের ফোন-দরজা — `/api/v1/inventory/…` (মালিক, ১৭ অক্টোবর ২০২৬; [[StockPhoneController]])।
 *
 * ⓘ মডিউলের নিজের ফাইল, তাই এখানে কেবল `api` মিডলওয়্যার আর `api` উপসর্গ আসে ([[ModuleServiceProvider::registerRoutes()]]) —
 * বাকি পাহারা হাতে, বিক্রয়ের ফোন-দরজার হুবহু: টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, আর ফোনে মজুদ বন্ধ থাকলে ৪০৩ `module_off`।
 * চাবি দেখে কন্ট্রোলার নিজে। ⭐ প্রতিটা লেখা একবারই — [[RemembersAPhoneWrite]]।
 */
Route::prefix('v1/inventory')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':inventory',
    ])
    ->group(function (): void {
        Route::get('/setup', [StockPhoneController::class, 'setup'])->name('stock.setup');
        Route::get('/placement', [StockPhoneController::class, 'placement'])->name('stock.placement');
        Route::post('/placement', [StockPhoneController::class, 'place'])->middleware(RemembersAPhoneWrite::class)->name('stock.place');
        Route::post('/adjust', [StockPhoneController::class, 'adjust'])->middleware(RemembersAPhoneWrite::class)->name('stock.adjust');
        Route::get('/counts', [StockPhoneController::class, 'counts'])->name('stock.counts');
        Route::post('/counts/{id}/approve', [StockPhoneController::class, 'approveCount'])->whereUuid('id')
            ->middleware(RemembersAPhoneWrite::class)->name('stock.count.approve');
        Route::post('/issue', [StockPhoneController::class, 'issue'])->middleware(RemembersAPhoneWrite::class)->name('stock.issue');
        Route::post('/opening', [StockPhoneController::class, 'opening'])->middleware(RemembersAPhoneWrite::class)->name('stock.opening');
        Route::post('/lot', [StockPhoneController::class, 'lot'])->middleware(RemembersAPhoneWrite::class)->name('stock.lot');

        // ⭐ মান যাচাই আর সিরিয়াল নম্বর (মালিক, ১৭ অক্টোবর ২০২৬; [[QualityPhoneController]], [[SerialPhoneController]])
        Route::get('/quality', [QualityPhoneController::class, 'index'])->name('quality.index');
        Route::post('/quality', [QualityPhoneController::class, 'store'])->middleware(RemembersAPhoneWrite::class)->name('quality.store');
        Route::get('/quality/{id}', [QualityPhoneController::class, 'show'])->whereUuid('id')->name('quality.show');
        Route::post('/quality/{id}/decide', [QualityPhoneController::class, 'decide'])->whereUuid('id')
            ->middleware(RemembersAPhoneWrite::class)->name('quality.decide');
        Route::post('/quality/{id}/dispose', [QualityPhoneController::class, 'dispose'])->whereUuid('id')
            ->middleware(RemembersAPhoneWrite::class)->name('quality.dispose');
        Route::get('/serials', [SerialPhoneController::class, 'index'])->name('serial.index');
        Route::post('/serials', [SerialPhoneController::class, 'store'])->middleware(RemembersAPhoneWrite::class)->name('serial.store');
        Route::post('/serials/issue', [SerialPhoneController::class, 'issue'])->middleware(RemembersAPhoneWrite::class)->name('serial.issue');
    });
