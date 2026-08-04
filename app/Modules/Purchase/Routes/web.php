<?php

declare(strict_types=1);

use App\Modules\Purchase\Http\Controllers\PurchaseBillController;
use App\Modules\Purchase\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchase\Http\Controllers\PurchaseReceiptController;
use App\Modules\Purchase\Http\Controllers\PurchaseReportController;
use Illuminate\Support\Facades\Route;

/*
 * Purchase মডিউলের রুট — ModuleServiceProvider নিজে নিবন্ধন করে
 * (সেকশন ১৯.৩)। নামের উপসর্গ "purchase." আপনাআপনি বসে।
 *
 * স্থির পথ {model}-এর আগে, আর {model} সংখ্যায় বাঁধা — নাহলে
 * /orders/reports কে একটা id ভেবে বাইন্ডিং ৪০৪ দিত।
 */

Route::middleware('auth')->prefix('purchase')->group(function () {

    Route::prefix('orders')->name('order.')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');
        Route::get('/create', [PurchaseOrderController::class, 'create'])->name('create');
        Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');
        Route::get('/{order}', [PurchaseOrderController::class, 'show'])->whereNumber('order')->name('show');
        Route::get('/{order}/edit', [PurchaseOrderController::class, 'edit'])->whereNumber('order')->name('edit');
        Route::put('/{order}', [PurchaseOrderController::class, 'update'])->whereNumber('order')->name('update');
        Route::post('/{order}/confirm', [PurchaseOrderController::class, 'confirm'])->whereNumber('order')->name('confirm');
        Route::post('/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('order')->name('cancel');
    });

    Route::prefix('receipts')->name('receipt.')->group(function () {
        Route::get('/', [PurchaseReceiptController::class, 'index'])->name('index');
        Route::get('/create', [PurchaseReceiptController::class, 'create'])->name('create');
        Route::post('/', [PurchaseReceiptController::class, 'store'])->name('store');
        Route::get('/{receipt}', [PurchaseReceiptController::class, 'show'])->whereNumber('receipt')->name('show');
        Route::get('/{receipt}/edit', [PurchaseReceiptController::class, 'edit'])->whereNumber('receipt')->name('edit');
        Route::put('/{receipt}', [PurchaseReceiptController::class, 'update'])->whereNumber('receipt')->name('update');
        Route::post('/{receipt}/confirm', [PurchaseReceiptController::class, 'confirm'])->whereNumber('receipt')->name('confirm');
        Route::post('/{receipt}/cancel', [PurchaseReceiptController::class, 'cancel'])->whereNumber('receipt')->name('cancel');
    });

    Route::prefix('bills')->name('bill.')->group(function () {
        Route::get('/', [PurchaseBillController::class, 'index'])->name('index');
        Route::get('/create', [PurchaseBillController::class, 'create'])->name('create');
        Route::post('/', [PurchaseBillController::class, 'store'])->name('store');
        Route::get('/{bill}', [PurchaseBillController::class, 'show'])->whereNumber('bill')->name('show');
        Route::get('/{bill}/edit', [PurchaseBillController::class, 'edit'])->whereNumber('bill')->name('edit');
        Route::put('/{bill}', [PurchaseBillController::class, 'update'])->whereNumber('bill')->name('update');
        Route::post('/{bill}/confirm', [PurchaseBillController::class, 'confirm'])->whereNumber('bill')->name('confirm');
        Route::post('/{bill}/cancel', [PurchaseBillController::class, 'cancel'])->whereNumber('bill')->name('cancel');
    });

    Route::get('/reports/{slug}', [PurchaseReportController::class, 'show'])->name('report.show');
});
