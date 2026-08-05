<?php

declare(strict_types=1);

use App\Modules\Sales\Http\Controllers\CollectionController;
use App\Modules\Sales\Http\Controllers\DeliveryChallanController;
use App\Modules\Sales\Http\Controllers\DirectSaleController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\SalesInvoiceController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Http\Controllers\SalesReportController;
use Illuminate\Support\Facades\Route;

/*
 * Sales মডিউলের রুট — ModuleServiceProvider নিজে নিবন্ধন করে (সেকশন ১৯.৩)।
 * স্থির পথ {model}-এর আগে, আর {model} সংখ্যায় বাঁধা।
 */

Route::middleware('auth')->prefix('sales')->group(function () {

    /*
     * কাউন্টার — সবচেয়ে উপরে, কারণ দিনের সবচেয়ে বেশি ব্যবহৃত পর্দা এটাই।
     */
    Route::prefix('pos')->name('pos.')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('index');
        Route::post('/', [PosController::class, 'checkout'])->name('checkout');
        Route::get('/lookup', [PosController::class, 'lookup'])->name('lookup');
    });

    /*
     * সরাসরি বিক্রয় — অর্ডার ছাড়াই মাল ও বিল, এক চাপে।
     *
     * তালিকার কোনো পর্দা নেই: যা তৈরি হয় সেগুলো চালান ও বিলের তালিকাতেই
     * দেখা যায়। আলাদা তালিকা রাখলে একই চালান দুই জায়গায় থাকত, আর
     * "কত মাল বেরিয়েছে" প্রশ্নের দুইটা উত্তর হত।
     */
    Route::prefix('direct')->name('direct.')->group(function () {
        Route::get('/', [DirectSaleController::class, 'create'])->name('create');
        Route::post('/', [DirectSaleController::class, 'store'])->name('store');
    });

    Route::prefix('orders')->name('order.')->group(function () {
        Route::get('/', [SalesOrderController::class, 'index'])->name('index');
        Route::get('/create', [SalesOrderController::class, 'create'])->name('create');
        Route::post('/', [SalesOrderController::class, 'store'])->name('store');
        Route::get('/{order}', [SalesOrderController::class, 'show'])->whereNumber('order')->name('show');
        Route::get('/{order}/edit', [SalesOrderController::class, 'edit'])->whereNumber('order')->name('edit');
        Route::put('/{order}', [SalesOrderController::class, 'update'])->whereNumber('order')->name('update');
        Route::post('/{order}/confirm', [SalesOrderController::class, 'confirm'])->whereNumber('order')->name('confirm');
        Route::post('/{order}/cancel', [SalesOrderController::class, 'cancel'])->whereNumber('order')->name('cancel');
    });

    Route::prefix('challans')->name('challan.')->group(function () {
        Route::get('/', [DeliveryChallanController::class, 'index'])->name('index');
        Route::get('/create', [DeliveryChallanController::class, 'create'])->name('create');
        Route::post('/', [DeliveryChallanController::class, 'store'])->name('store');
        Route::get('/{challan}', [DeliveryChallanController::class, 'show'])->whereNumber('challan')->name('show');
        Route::get('/{challan}/edit', [DeliveryChallanController::class, 'edit'])->whereNumber('challan')->name('edit');
        Route::put('/{challan}', [DeliveryChallanController::class, 'update'])->whereNumber('challan')->name('update');
        Route::post('/{challan}/confirm', [DeliveryChallanController::class, 'confirm'])->whereNumber('challan')->name('confirm');
        Route::post('/{challan}/cancel', [DeliveryChallanController::class, 'cancel'])->whereNumber('challan')->name('cancel');
    });

    Route::prefix('invoices')->name('invoice.')->group(function () {
        Route::get('/', [SalesInvoiceController::class, 'index'])->name('index');
        Route::get('/create', [SalesInvoiceController::class, 'create'])->name('create');
        Route::post('/', [SalesInvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [SalesInvoiceController::class, 'show'])->whereNumber('invoice')->name('show');
        Route::get('/{invoice}/edit', [SalesInvoiceController::class, 'edit'])->whereNumber('invoice')->name('edit');
        Route::put('/{invoice}', [SalesInvoiceController::class, 'update'])->whereNumber('invoice')->name('update');
        Route::post('/{invoice}/confirm', [SalesInvoiceController::class, 'confirm'])->whereNumber('invoice')->name('confirm');
        Route::post('/{invoice}/cancel', [SalesInvoiceController::class, 'cancel'])->whereNumber('invoice')->name('cancel');
    });

    Route::prefix('collections')->name('collection.')->group(function () {
        Route::get('/', [CollectionController::class, 'index'])->name('index');
        Route::get('/create', [CollectionController::class, 'create'])->name('create');
        Route::post('/', [CollectionController::class, 'store'])->name('store');
        Route::get('/{collection}', [CollectionController::class, 'show'])->whereNumber('collection')->name('show');
        Route::get('/{collection}/edit', [CollectionController::class, 'edit'])->whereNumber('collection')->name('edit');
        Route::put('/{collection}', [CollectionController::class, 'update'])->whereNumber('collection')->name('update');
        Route::post('/{collection}/confirm', [CollectionController::class, 'confirm'])->whereNumber('collection')->name('confirm');
        Route::post('/{collection}/cancel', [CollectionController::class, 'cancel'])->whereNumber('collection')->name('cancel');
    });

    /*
     * ছাপার রুট — ছয়টা কাগজ, তিনটা মাপ (?paper=a4|80mm|58mm)।
     *
     * সবগুলো GET, কারণ ছাপা কিছু বদলায় না — আর তাতে কাগজটা বুকমার্ক করা
     * যায়, আর ব্রাউজারের ফিরে যাওয়ার বোতামও ভাঙে না।
     */
    Route::prefix('print')->name('print.')->group(function () {
        Route::get('/invoice/{invoice}', [SalesPrintController::class, 'invoice'])
            ->whereNumber('invoice')->name('invoice');
        Route::get('/invoice/{invoice}/draft', [SalesPrintController::class, 'draft'])
            ->whereNumber('invoice')->name('draft');
        Route::get('/challan/{challan}', [SalesPrintController::class, 'challan'])
            ->whereNumber('challan')->name('challan');
        Route::get('/challan/{challan}/gatepass', [SalesPrintController::class, 'gatepass'])
            ->whereNumber('challan')->name('gatepass');
        Route::get('/order/{order}', [SalesPrintController::class, 'order'])
            ->whereNumber('order')->name('order');
        Route::get('/order/{order}/delivery-order', [SalesPrintController::class, 'deliveryOrder'])
            ->whereNumber('order')->name('delivery_order');
        Route::get('/collection/{collection}', [SalesPrintController::class, 'receipt'])
            ->whereNumber('collection')->name('receipt');
    });

    Route::get('/reports/{slug}', [SalesReportController::class, 'show'])->name('report.show');
});
