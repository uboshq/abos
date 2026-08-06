<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockReportController;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
use App\Modules\Inventory\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory মডিউলের রুট — ModuleServiceProvider নিজে নিবন্ধন করে
 * (সেকশন ১৯.৩)। নামের উপসর্গ "inventory." আপনাআপনি বসে।
 *
 * প্রতিটা গ্রুপে স্থির পথ {model}-এর আগে, আর {model} সংখ্যায় বাঁধা —
 * নাহলে /products/reports কে একটা id ভেবে বাইন্ডিং ৪০৪ দিত।
 */

Route::middleware('auth')->prefix('inventory')->group(function () {

    Route::prefix('products')->name('product.')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->name('index');
        Route::get('/create', [ProductController::class, 'create'])->name('create');
        Route::post('/', [ProductController::class, 'store'])->name('store');
        Route::get('/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('show');
        Route::get('/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->name('edit');
        Route::put('/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('update');
        Route::delete('/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->name('destroy');
        Route::post('/{product}/activate', [ProductController::class, 'activate'])->whereNumber('product')->name('activate');
    });

    Route::prefix('warehouses')->name('warehouse.')->group(function () {
        Route::get('/', [WarehouseController::class, 'index'])->name('index');
        Route::get('/create', [WarehouseController::class, 'create'])->name('create');
        Route::post('/', [WarehouseController::class, 'store'])->name('store');
        Route::get('/{warehouse}/edit', [WarehouseController::class, 'edit'])->whereNumber('warehouse')->name('edit');
        Route::put('/{warehouse}', [WarehouseController::class, 'update'])->whereNumber('warehouse')->name('update');
        Route::delete('/{warehouse}', [WarehouseController::class, 'destroy'])->whereNumber('warehouse')->name('destroy');
    });

    Route::prefix('stock')->name('stock.')->group(function () {
        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('/adjust', [StockController::class, 'adjust'])->name('adjust');
        Route::post('/adjust', [StockController::class, 'storeAdjustment'])->name('adjust.store');
        Route::post('/hold', [StockController::class, 'storeHold'])->name('hold');
        Route::post('/release', [StockController::class, 'storeRelease'])->name('release');
    });

    Route::prefix('transfers')->name('transfer.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('index');
        Route::get('/create', [StockTransferController::class, 'create'])->name('create');
        Route::post('/', [StockTransferController::class, 'store'])->name('store');
        Route::get('/{transfer}', [StockTransferController::class, 'show'])->whereNumber('transfer')->name('show');
        Route::get('/{transfer}/edit', [StockTransferController::class, 'edit'])->whereNumber('transfer')->name('edit');
        Route::put('/{transfer}', [StockTransferController::class, 'update'])->whereNumber('transfer')->name('update');

        // রওনা আর বুঝে নেওয়া — দুইটা আলাদা কাজ, দুইটা আলাদা চাবি
        Route::post('/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])
            ->whereNumber('transfer')->name('dispatch');
        Route::post('/{transfer}/receive', [StockTransferController::class, 'receive'])
            ->whereNumber('transfer')->name('receive');
        Route::post('/{transfer}/cancel', [StockTransferController::class, 'cancel'])
            ->whereNumber('transfer')->name('cancel');
    });

    Route::get('/reports/{slug}', [StockReportController::class, 'show'])->name('report.show');
});
