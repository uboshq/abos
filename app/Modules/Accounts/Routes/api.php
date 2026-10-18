<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Middleware\RefuseModulesOffOnThePhone;
use App\Http\Middleware\ResolveCompanyContext;
use App\Modules\Accounts\Http\Controllers\VoucherApiController;
use Illuminate\Support\Facades\Route;

/*
 * হিসাবের ফোন-দরজা — `/api/v1/accounts/…` (মালিক, ৭ অক্টোবর ২০২৬: ফোনে অফিসের লোকের ভাউচার)।
 *
 * ⓘ HR আর বিক্রয়ের ফোন-দরজার একই পাহারা: টোকেন, `abilities:app`, কোম্পানির প্রসঙ্গ, আর ফোনে হিসাব বন্ধ থাকলে ৪০৩
 * `module_off`। চাবি দেখে কন্ট্রোলার নিজে ([[VoucherApiController]] — লেখা, রিপোর্ট বা পাকা করার চাবি)। নতুন ভাউচার
 * লেখা সিঙ্কের সারি দিয়ে ([[VoucherSync]]); ছাপা দলিলের দরজায় (`/documents/Voucher/{id}/pdf`)।
 */
Route::prefix('v1/accounts')
    ->middleware([
        'auth:sanctum',
        'throttle:app',
        'abilities:'.AuthController::APP,
        ResolveCompanyContext::class,
        RefuseModulesOffOnThePhone::class.':accounts',
    ])
    ->group(function (): void {
        Route::get('/vouchers/setup', [VoucherApiController::class, 'setup'])->name('voucher.setup');
        Route::get('/vouchers', [VoucherApiController::class, 'index'])->name('voucher.index');
        Route::get('/vouchers/{id}', [VoucherApiController::class, 'show'])->whereUuid('id')->name('voucher.show');
        Route::post('/vouchers/{id}/post', [VoucherApiController::class, 'post'])->whereUuid('id')->name('voucher.post');

        // ⭐ হিসাবের ছক আর খাতের খাতা, ফোনের নিজের পর্দায় — ওয়েবের একই সেবা ও চাবি (মালিক, ১৭ অক্টোবর ২০২৬; [[AccountLedgerApiController]])
        Route::get('/chart', [\App\Modules\Accounts\Http\Controllers\AccountLedgerApiController::class, 'chart'])->name('chart.index');
        Route::get('/chart/{id}/ledger', [\App\Modules\Accounts\Http\Controllers\AccountLedgerApiController::class, 'ledger'])->whereUuid('id')->name('chart.ledger');

        // ⭐ টাকার হাতবদল আর নগদ গোনা (মালিক, ১৭ অক্টোবর ২০২৬; [[MoneyPhoneController]])
        Route::get('/custody', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'custody'])->name('money.custody');
        Route::get('/money/setup', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'setup'])->name('money.setup');
        Route::get('/transfers', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'transfers'])->name('money.transfers');
        Route::post('/transfers', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'sendTransfer'])->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class.':required')->name('money.transfer.send');
        Route::get('/transfers/{id}', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'transfer'])->whereUuid('id')->name('money.transfer');
        Route::post('/transfers/{id}/confirm', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'confirmTransfer'])->whereUuid('id')->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class.':required')->name('money.transfer.confirm');
        Route::post('/transfers/{id}/cancel', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'cancelTransfer'])->whereUuid('id')->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class.':required')->name('money.transfer.cancel');
        Route::get('/cash-counts/setup', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'countSetup'])->name('money.count.setup');
        Route::get('/cash-counts', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'counts'])->name('money.counts');
        Route::post('/cash-counts', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'recordCount'])->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class.':required')->name('money.count.record');
        Route::get('/cash-counts/{id}', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'count'])->whereUuid('id')->name('money.count');
        Route::post('/cash-counts/{id}/approve', [\App\Modules\Accounts\Http\Controllers\MoneyPhoneController::class, 'approveCount'])->whereUuid('id')->middleware(\App\Http\Middleware\RemembersAPhoneWrite::class.':required')->name('money.count.approve');
    });
