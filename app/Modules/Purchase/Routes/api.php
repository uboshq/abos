<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Purchase\Http\Controllers\PurchaseApiController;
use Illuminate\Support\Facades\Route;

/*
 * ⭐ ক্রয়ের ফোন-দরজা — `/api/v1/purchase/…` (মালিক, ৬ অক্টোবর ২০২৬: "অ্যাপে … principal list আর purchase list দরকার")।
 *
 * ⓘ কেবল পড়া। পাহারা বিক্রয়ের ফোন-দরজার হুবহু ([[Sales/Routes/api.php]]): টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, আর
 * ফোনে ক্রয় বন্ধ থাকলে ৪০৩ `module_off`। চাবি দেখে কন্ট্রোলার নিজে ([[PurchaseApiController::middleware()]])।
 * ⓘ প্রিন্সিপালের তালিকাও এখানে: ফোনের মডিউল-সুইচে "সরবরাহকারী" আলাদা নেই, প্রিন্সিপাল ক্রয়েরই অংশ।
 */
Route::prefix('v1/purchase')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':purchase',
    ])
    ->group(function (): void {
        Route::get('/principals', [PurchaseApiController::class, 'principals'])->name('principal.index');
        Route::get('/principals/{id}', [PurchaseApiController::class, 'principal'])->whereUuid('id')->name('principal.show');
        Route::get('/purchases', [PurchaseApiController::class, 'purchases'])->name('purchase.index');
        Route::get('/purchases/{kind}/{id}', [PurchaseApiController::class, 'purchase'])
            ->where('kind', 'bill|receipt')->whereUuid('id')->name('purchase.show');
    });
