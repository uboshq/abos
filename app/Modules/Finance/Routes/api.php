<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Finance\Http\Controllers\FinanceApiController;
use Illuminate\Support\Facades\Route;

/*
 * অর্থের ফোন-দরজা — `/api/v1/finance/…` (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬: বাকি মডিউলগুলো অ্যাপে)।
 *
 * ⓘ কেবল পড়া: জমা, ব্যাংকের ঋণ ও কিস্তি, ভাড়া (আমরা দিই · আমরা পাই), হাতধার — তালিকা আর একটা কাগজ।
 * পাহারা বিক্রয়ের ফোন-দরজার হুবহু ([[Sales/Routes/api.php]]): টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, আর ফোনে অর্থ
 * বন্ধ থাকলে ৪০৩ `module_off`। চাবি ওয়েবের পর্দার একই চাবি, কন্ট্রোলারে ([[FinanceApiController::middleware()]])।
 */
Route::prefix('v1/finance')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':finance',
    ])
    ->group(function (): void {
        Route::get('/deposits', [FinanceApiController::class, 'deposits'])->name('phone.deposits');
        Route::get('/deposits/{id}', [FinanceApiController::class, 'deposit'])->whereUuid('id')->name('phone.deposit');
        Route::get('/loans', [FinanceApiController::class, 'loans'])->name('phone.loans');
        Route::get('/loans/{id}', [FinanceApiController::class, 'loan'])->whereUuid('id')->name('phone.loan');
        Route::get('/rents', [FinanceApiController::class, 'rents'])->name('phone.rents');
        Route::get('/rents/{side}/{id}', [FinanceApiController::class, 'rent'])
            ->where('side', 'pay|let')->whereUuid('id')->name('phone.rent');
        Route::get('/hand-loans', [FinanceApiController::class, 'handLoans'])->name('phone.hand_loans');
        Route::get('/hand-loans/{id}', [FinanceApiController::class, 'handLoan'])->whereUuid('id')->name('phone.hand_loan');
    });
