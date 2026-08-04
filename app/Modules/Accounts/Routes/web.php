<?php

declare(strict_types=1);

use App\Modules\Accounts\Http\Controllers\CashTillController;
use App\Modules\Accounts\Http\Controllers\ChartOfAccountsController;
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
});
