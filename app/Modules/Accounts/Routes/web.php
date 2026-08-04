<?php

declare(strict_types=1);

use App\Modules\Accounts\Http\Controllers\CashCountController;
use App\Modules\Accounts\Http\Controllers\CashTillController;
use App\Modules\Accounts\Http\Controllers\ChartOfAccountsController;
use App\Modules\Accounts\Http\Controllers\MoneyTransferController;
use App\Modules\Accounts\Http\Controllers\ReportController;
use App\Modules\Accounts\Http\Controllers\VoucherController;
use App\Modules\Accounts\Models\Account;
use Illuminate\Support\Facades\Route;

/*
 * Accounts মডিউলের নিজের রুট — ModuleServiceProvider নিজে থেকে নিবন্ধন
 * করে (সেকশন ১৯.৩)। নামের উপসর্গ "accounts." আপনাআপনি বসে।
 */

Route::middleware('auth')->prefix('accounts')->group(function () {

    Route::prefix('chart-of-accounts')->name('coa.')->group(function () {
        Route::get('/', [ChartOfAccountsController::class, 'index'])->name('index');
        Route::get('/create', [ChartOfAccountsController::class, 'create'])->name('create');
        Route::post('/', [ChartOfAccountsController::class, 'store'])->name('store');

        /*
         * install রুটটা {account} প্যাটার্নের আগে — নাহলে
         * /chart-of-accounts/install-standard কে একটা খাতের id ভেবে
         * বাইন্ডিং ৪০৪ দিত।
         */
        Route::post('/install-standard', [ChartOfAccountsController::class, 'installStandardChart'])
            ->middleware('can:create,'.Account::class)
            ->name('install');

        Route::get('/{account}', [ChartOfAccountsController::class, 'show'])->name('show');
        Route::get('/{account}/edit', [ChartOfAccountsController::class, 'edit'])->name('edit');
        Route::put('/{account}', [ChartOfAccountsController::class, 'update'])->name('update');
        Route::delete('/{account}', [ChartOfAccountsController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('cash-tills')->name('till.')->group(function () {
        Route::get('/', [CashTillController::class, 'index'])->name('index');
        Route::get('/create', [CashTillController::class, 'create'])->name('create');
        Route::post('/', [CashTillController::class, 'store'])->name('store');
        Route::get('/{till}', [CashTillController::class, 'show'])->name('show');
        Route::get('/{till}/edit', [CashTillController::class, 'edit'])->name('edit');
        Route::put('/{till}', [CashTillController::class, 'update'])->name('update');
        Route::delete('/{till}', [CashTillController::class, 'destroy'])->name('destroy');

        // resource ছকের বাইরে, তাই অনুমতি কন্ট্রোলারে হাতে যাচাই করা হয়
        Route::post('/{till}/primary', [CashTillController::class, 'makePrimary'])->name('primary');
    });

    /*
     * ভাউচার — ধরনটা URL-এ।
     *
     * /vouchers/receipt, /vouchers/journal — মেনুর পাঁচটা সারি পাঁচটা
     * আলাদা ঠিকানায় যায়, অথচ কন্ট্রোলার এক। ধরনটা ক্যোয়ারি প্যারামিটার
     * হলে ছাপার শিরোনাম ও ব্রেডক্রাম্ব দুইটাই অনিশ্চিত হত।
     *
     * {voucher} রুটগুলো ধরন ছাড়া, কারণ একটা ভাউচার নিজেই জানে সে কোন
     * ধরনের — ঠিকানায় ধরনটা আবার লিখলে দুইটা অমিল হতে পারত।
     */
    Route::prefix('vouchers')->name('voucher.')->group(function () {
        Route::get('/{voucher}', [VoucherController::class, 'show'])
            ->whereNumber('voucher')->name('show');
        Route::get('/{voucher}/edit', [VoucherController::class, 'edit'])
            ->whereNumber('voucher')->name('edit');
        Route::put('/{voucher}', [VoucherController::class, 'update'])
            ->whereNumber('voucher')->name('update');
        Route::post('/{voucher}/post', [VoucherController::class, 'post'])
            ->whereNumber('voucher')->name('post');
        Route::post('/{voucher}/cancel', [VoucherController::class, 'cancel'])
            ->whereNumber('voucher')->name('cancel');

        // ধরনভিত্তিক রুটগুলো শেষে — নাহলে /vouchers/receipt কে একটা
        // ভাউচারের id ভেবে বাইন্ডিং ৪০৪ দিত
        Route::get('/{type}', [VoucherController::class, 'index'])->name('index');
        Route::get('/{type}/create', [VoucherController::class, 'create'])->name('create');
        Route::post('/{type}', [VoucherController::class, 'store'])->name('store');
    });

    Route::prefix('money-transfers')->name('transfer.')->group(function () {
        Route::get('/', [MoneyTransferController::class, 'index'])->name('index');
        Route::get('/create', [MoneyTransferController::class, 'create'])->name('create');
        Route::post('/', [MoneyTransferController::class, 'store'])->name('store');
        Route::get('/{transfer}', [MoneyTransferController::class, 'show'])->name('show');
        Route::post('/{transfer}/confirm', [MoneyTransferController::class, 'confirm'])->name('confirm');
        Route::post('/{transfer}/cancel', [MoneyTransferController::class, 'cancel'])->name('cancel');
    });

    /*
     * রিপোর্ট — আটটা, একটাই কন্ট্রোলার।
     *
     * ঠিকানায় engine-এর ভেতরের কী নয়, পড়ার মতো নাম: /reports/day-book।
     * ভেতরের কী বদলালে বুকমার্ক ভাঙত।
     */
    Route::get('/reports/{slug}', [ReportController::class, 'show'])->name('report.show');

    Route::prefix('cash-counts')->name('count.')->group(function () {
        Route::get('/', [CashCountController::class, 'index'])->name('index');
        Route::get('/create', [CashCountController::class, 'create'])->name('create');
        Route::post('/', [CashCountController::class, 'store'])->name('store');
        Route::get('/{count}', [CashCountController::class, 'show'])->name('show');
        Route::post('/{count}/approve', [CashCountController::class, 'approve'])->name('approve');
    });
});
