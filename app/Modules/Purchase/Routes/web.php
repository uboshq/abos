<?php

declare(strict_types=1);

use App\Modules\Purchase\Http\Controllers\DirectPurchaseController;
use App\Modules\Purchase\Http\Controllers\PaymentController;
use App\Modules\Purchase\Http\Controllers\PurchaseBillController;
use App\Modules\Purchase\Http\Controllers\PurchaseOrderController;
use App\Modules\Purchase\Http\Controllers\PurchasePrintController;
use App\Modules\Purchase\Http\Controllers\PurchaseReceiptController;
use App\Modules\Purchase\Http\Controllers\PurchaseReportController;
use App\Modules\Purchase\Http\Controllers\PurchaseReturnController;
use Illuminate\Support\Facades\Route;

/*
 * Purchase মডিউলের রুট — ModuleServiceProvider নিজে নিবন্ধন করে
 * (সেকশন ১৯.৩)। নামের উপসর্গ "purchase." আপনাআপনি বসে।
 *
 * স্থির পথ {model}-এর আগে, আর {model} সংখ্যায় বাঁধা — নাহলে
 * /orders/reports কে একটা id ভেবে বাইন্ডিং ৪০৪ দিত।
 */

Route::middleware('auth')->prefix('purchase')->group(function () {
    /*
     * ছাপা — চারটা কাগজ, তিনটা মাপে (?paper=58mm|80mm|a4)।
     *
     * সবগুলো GET, কারণ ছাপা কিছু বদলায় না — আর তাতে কাগজটা বুকমার্ক করা
     * যায় আর ব্রাউজারের ফিরে যাওয়ার বোতামও ভাঙে না। Sales-এর ছাপার
     * রুটগুলোও ঠিক একই ছাঁচে।
     *
     * গ্রুপটা উপরে, কারণ `print` একটা স্থির অংশ আর নিচের রুটগুলোয়
     * `{bill}`/`{order}` বসে — স্থির পথ আগে না বসালে একদিন একটা
     * প্যারামিটার ওটাকে গিলে ফেলে।
     */
    Route::prefix('print')->name('print.')->group(function () {
        Route::get('/bill/{bill}', [PurchasePrintController::class, 'bill'])
            ->whereNumber('bill')->name('bill');
        Route::get('/order/{order}', [PurchasePrintController::class, 'order'])
            ->whereNumber('order')->name('order');
        Route::get('/receipt/{receipt}', [PurchasePrintController::class, 'receipt'])
            ->whereNumber('receipt')->name('receipt');
        Route::get('/return/{return}', [PurchasePrintController::class, 'creditNote'])
            ->whereNumber('return')->name('return');
    });

    /*
     * সরাসরি ক্রয় চালান — এক পর্দায় মাল, দাম আর টাকা।
     *
     * তালিকা নেই ইচ্ছাকৃতভাবে: এটা একটা এন্ট্রির পর্দা, আর যা বসে
     * সেগুলো ক্রয় বিলের তালিকাতেই থাকে। আলাদা তালিকা রাখলে একই কাগজ
     * দুই জায়গায় দেখা যেত, আর কোনটা আসল তা নিয়ে প্রশ্ন উঠত।
     */
    Route::prefix('direct')->name('direct.')->group(function () {
        Route::get('/', [DirectPurchaseController::class, 'create'])->name('create');
        Route::post('/', [DirectPurchaseController::class, 'store'])->name('store');

        /*
         * এই সরবরাহকারীর কাছ থেকে গতবারের দরগুলো — একবারে সব পণ্যের।
         *
         * ⚠️ কেন একটা আলাদা ঠিকানা লাগল: সরবরাহকারী বাছা হয় পাতা খোলার
         * **পরে**, তাই পাতার সাথে পাঠানো যায় না। আর সব সরবরাহকারীর সব
         * দর একসাথে পাঠানো মানে হাজার হাজার সারি, যার মধ্যে একজনেরটা
         * ছাড়া বাকি সব অপ্রয়োজনীয়।
         *
         * ⓘ অনুমতি আসে কন্ট্রোলারের নিজের `can:purchase.bill.create`
         * থেকে — যে পর্দাটা খুলতে পারেন, তিনিই কেবল এই সংখ্যাগুলো
         * দেখতে পাবেন। দর কার কাছে কত, সেটা ব্যবসার গোপন কথা।
         */
        Route::get('/last-rates/{supplier}', [DirectPurchaseController::class, 'lastRates'])
            ->whereNumber('supplier')->name('last_rates');
    });

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

    Route::prefix('payments')->name('payment.')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->name('index');
        Route::get('/create', [PaymentController::class, 'create'])->name('create');
        Route::post('/', [PaymentController::class, 'store'])->name('store');
        Route::get('/{payment}', [PaymentController::class, 'show'])->whereNumber('payment')->name('show');
        Route::get('/{payment}/edit', [PaymentController::class, 'edit'])->whereNumber('payment')->name('edit');
        Route::put('/{payment}', [PaymentController::class, 'update'])->whereNumber('payment')->name('update');
        Route::post('/{payment}/confirm', [PaymentController::class, 'confirm'])->whereNumber('payment')->name('confirm');
        Route::post('/{payment}/cancel', [PaymentController::class, 'cancel'])->whereNumber('payment')->name('cancel');
    });

    Route::prefix('returns')->name('return.')->group(function () {
        Route::get('/', [PurchaseReturnController::class, 'index'])->name('index');
        Route::get('/create', [PurchaseReturnController::class, 'create'])->name('create');
        Route::post('/', [PurchaseReturnController::class, 'store'])->name('store');
        Route::get('/{return}', [PurchaseReturnController::class, 'show'])->whereNumber('return')->name('show');
        Route::get('/{return}/edit', [PurchaseReturnController::class, 'edit'])->whereNumber('return')->name('edit');
        Route::put('/{return}', [PurchaseReturnController::class, 'update'])->whereNumber('return')->name('update');
        Route::post('/{return}/confirm', [PurchaseReturnController::class, 'confirm'])->whereNumber('return')->name('confirm');
        Route::post('/{return}/cancel', [PurchaseReturnController::class, 'cancel'])->whereNumber('return')->name('cancel');
    });

    Route::get('/reports/{slug}', [PurchaseReportController::class, 'show'])->name('report.show');
});
