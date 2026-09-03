<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePortalStillOpen;
use App\Modules\Sales\Http\Controllers\CollectionController;
use App\Modules\Sales\Http\Controllers\CommissionClaimController;
use App\Modules\Sales\Http\Controllers\DeliveryChallanController;
use App\Modules\Sales\Http\Controllers\DepositClaimController;
use App\Modules\Sales\Http\Controllers\DirectSaleController;
use App\Modules\Sales\Http\Controllers\LotTraceController;
use App\Modules\Sales\Http\Controllers\PortalController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\PrintQueueController;
use App\Modules\Sales\Http\Controllers\SalesInvoiceController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Http\Controllers\SalesReportController;
use App\Modules\Sales\Http\Controllers\SalesReturnController;
use App\Modules\Sales\Http\Controllers\SalesTargetController;
use App\Modules\Sales\Http\Controllers\SchemeController;
use App\Modules\Sales\Http\Controllers\ShiftController;
use App\Modules\Sales\Http\Controllers\ShipmentController;
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

        /*
         * কাউন্টারে ধরে রাখা বিল — ক্রেতা টাকা আনতে গেছেন।
         *
         * তোলাটা POST, GET নয়: তোলার সময় বিলটার parked_at মুছে যায়,
         * অর্থাৎ অবস্থা বদলায়। GET হলে ব্রাউজারের prefetch বা কারো
         * পাঠানো লিংকেই বিলটা কাউন্টারের তালিকা থেকে হারিয়ে যেত।
         */
        Route::post('/park', [PosController::class, 'park'])->name('park');

        Route::post('/{invoice}/resume', [PosController::class, 'resume'])
            ->whereNumber('invoice')->name('resume');

        /*
         * কাউন্টার থেকেই ফেরত।
         *
         * খোঁজাটা GET (কিছু বদলায় না), নেওয়াটা POST — মাল গুদামে ফেরে,
         * খাতায় দাখিলা বসে, আর টাকা ড্রয়ার থেকে যেতে পারে।
         */
        Route::get('/bill', [PosController::class, 'bill'])->name('bill');
        Route::post('/return', [PosController::class, 'takeBack'])->name('return');
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

    /*
     * শিপমেন্ট — গাড়ির একটা দিন।
     *
     * `settle` সারি ধরে, তাই ট্রিপ ও সারি দুইটাই ঠিকানায় থাকে — আর
     * কন্ট্রোলার মিলিয়ে দেখে সারিটা সত্যিই ওই ট্রিপের কি না, নাহলে
     * অন্য ট্রিপের সারিতে হাত দেওয়া যেত।
     */
    /*
     * লক্ষ্যমাত্রা — একটাই পর্দা, দেখা ও বসানো দুইটাই।
     *
     * দেখার চাবি আর বসানোর চাবি আলাদা: মালিক বসান, বিক্রয়কর্মী
     * নিজেরটা দেখেন — নিজের টার্গেট নিজে বদলাতে পারলে ওটা আর
     * টার্গেট থাকত না।
     */
    /*
     * ডিলারের কমিশন — কোম্পানির কাছে দাবি।
     *
     * তালিকাই প্রধান পর্দা, তাই আলাদা create/show নেই: বসানো হয়
     * তালিকার উপরের ফর্ম থেকে, আর সিদ্ধান্ত সারি থেকেই।
     */
    /*
     * ডিলারদের তোলা জমার দাবি — ডিপোর দিক।
     *
     * সিদ্ধান্ত সারি থেকেই, আলাদা পাতায় নয়: দিনে বিশটা দাবি যাচাই
     * করতে গিয়ে প্রতিটার জন্য যাওয়া-আসা করলে কেউ আর তালিকাটা খুলত না।
     */
    Route::prefix('deposit-claims')->name('claim.')->group(function () {
        Route::get('/', [DepositClaimController::class, 'index'])->name('index');
        Route::post('/{claim}/accept', [DepositClaimController::class, 'accept'])
            ->whereNumber('claim')->name('accept');
        Route::post('/{claim}/reject', [DepositClaimController::class, 'reject'])
            ->whereNumber('claim')->name('reject');
    });

    /*
     * স্কিম — কমিশনের নিয়ম যেখানে লেখা থাকে।
     *
     * ধাপগুলো স্কিমের নিজের পাতায় বসে ও মুছে, তাই ওদের রুট স্কিমের
     * নিচেই — নাহলে একটা ধাপ কোন স্কিমের তা ঠিকানা দেখে বলা যেত না।
     */
    Route::prefix('schemes')->name('scheme.')->group(function () {
        Route::get('/', [SchemeController::class, 'index'])->name('index');
        Route::post('/', [SchemeController::class, 'store'])->name('store');
        Route::get('/{scheme}', [SchemeController::class, 'show'])
            ->whereNumber('scheme')->name('show');
        Route::put('/{scheme}', [SchemeController::class, 'update'])
            ->whereNumber('scheme')->name('update');
        Route::post('/{scheme}/rules', [SchemeController::class, 'addRule'])
            ->whereNumber('scheme')->name('rule.add');
        Route::delete('/{scheme}/rules/{rule}', [SchemeController::class, 'removeRule'])
            ->whereNumber('scheme')->whereNumber('rule')->name('rule.remove');
        Route::post('/{scheme}/activate', [SchemeController::class, 'activate'])
            ->whereNumber('scheme')->name('activate');
        Route::post('/{scheme}/cancel', [SchemeController::class, 'cancel'])
            ->whereNumber('scheme')->name('cancel');
    });

    Route::prefix('commissions')->name('commission.')->group(function () {
        Route::get('/', [CommissionClaimController::class, 'index'])->name('index');
        Route::post('/', [CommissionClaimController::class, 'store'])->name('store');
        Route::post('/{claim}/settle', [CommissionClaimController::class, 'settle'])
            ->whereNumber('claim')->name('settle');
        Route::post('/{claim}/reject', [CommissionClaimController::class, 'reject'])
            ->whereNumber('claim')->name('reject');
    });

    Route::prefix('targets')->name('target.')->group(function () {
        Route::get('/', [SalesTargetController::class, 'index'])->name('index');
        Route::post('/', [SalesTargetController::class, 'store'])->name('store');
    });

    Route::prefix('shipments')->name('shipment.')->group(function () {
        Route::get('/', [ShipmentController::class, 'index'])->name('index');
        Route::get('/create', [ShipmentController::class, 'create'])->name('create');
        Route::post('/', [ShipmentController::class, 'store'])->name('store');
        Route::get('/{shipment}', [ShipmentController::class, 'show'])->whereNumber('shipment')->name('show');
        Route::get('/{shipment}/edit', [ShipmentController::class, 'edit'])->whereNumber('shipment')->name('edit');
        Route::put('/{shipment}', [ShipmentController::class, 'update'])->whereNumber('shipment')->name('update');
        Route::post('/{shipment}/dispatch', [ShipmentController::class, 'dispatch'])
            ->whereNumber('shipment')->name('dispatch');
        Route::post('/{shipment}/lines/{line}/settle', [ShipmentController::class, 'settle'])
            ->whereNumber(['shipment', 'line'])->name('settle');
        Route::post('/{shipment}/close', [ShipmentController::class, 'close'])
            ->whereNumber('shipment')->name('close');
        Route::post('/{shipment}/cancel', [ShipmentController::class, 'cancel'])
            ->whereNumber('shipment')->name('cancel');
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

        // চেকের খাতা থেকে আদায়ে-পোস্ট-করা চেকের ফেরত — param চেক, collection নয়
        Route::post('/cheque/{cheque}/bounce', [CollectionController::class, 'bounceCheque'])
            ->whereNumber('cheque')->name('cheque_bounce');
    });

    /*
     * ছাপার রুট — ছয়টা কাগজ, তিনটা মাপ (?paper=a4|80mm|58mm)।
     *
     * সবগুলো GET, কারণ ছাপা কিছু বদলায় না — আর তাতে কাগজটা বুকমার্ক করা
     * যায়, আর ব্রাউজারের ফিরে যাওয়ার বোতামও ভাঙে না।
     */
    /*
     * শিফট — ড্রয়ারটার জন্য কেউ একজন দায়ী।
     *
     * খোলা ও বন্ধ দুইটাই POST: অবস্থা বদলায়, আর টাকার দায় বদলায়।
     * Z-রিপোর্ট GET — ওটা প্রশ্ন, তাই লিংকটা পাঠানো যায়।
     */
    Route::prefix('shifts')->name('shift.')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->name('index');
        Route::post('/', [ShiftController::class, 'open'])->name('open');
        Route::post('/{shift}/close', [ShiftController::class, 'close'])
            ->whereNumber('shift')->name('close');
        Route::get('/{shift}', [ShiftController::class, 'show'])
            ->whereNumber('shift')->name('show');
    });

    /*
     * রিকল — "এই লটটা কাদের কাছে গেছে"।
     *
     * বিক্রয়ে, মজুদে নয়: উত্তরটা গ্রাহকের নাম ও ফোন নম্বরের তালিকা।
     * GET, কারণ এটা প্রশ্ন — লিংকটা কপি করে পাঠানো যায়।
     */
    Route::get('/lots/trace', [LotTraceController::class, 'show'])->name('lot.trace');

    /*
     * যে কাগজগুলো এখনো বেরোয়নি।
     *
     * তালিকাটা GET — একটা প্রশ্ন। "বেরিয়ে গেছে" চিহ্নিত করা POST,
     * কারণ ওটা অবস্থা বদলায়।
     */
    Route::prefix('print-queue')->name('print_queue.')->group(function () {
        Route::get('/', [PrintQueueController::class, 'index'])->name('index');
        Route::post('/{job}/settle', [PrintQueueController::class, 'settle'])
            ->whereNumber('job')->name('settle');
    });

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

    Route::prefix('returns')->name('return.')->group(function () {
        Route::get('/', [SalesReturnController::class, 'index'])->name('index');
        Route::get('/create', [SalesReturnController::class, 'create'])->name('create');
        Route::post('/', [SalesReturnController::class, 'store'])->name('store');
        Route::get('/{return}', [SalesReturnController::class, 'show'])->whereNumber('return')->name('show');
        Route::get('/{return}/edit', [SalesReturnController::class, 'edit'])->whereNumber('return')->name('edit');
        Route::put('/{return}', [SalesReturnController::class, 'update'])->whereNumber('return')->name('update');
        Route::post('/{return}/confirm', [SalesReturnController::class, 'confirm'])->whereNumber('return')->name('confirm');
        Route::post('/{return}/cancel', [SalesReturnController::class, 'cancel'])->whereNumber('return')->name('cancel');
    });

    Route::get('/reports/{slug}', [SalesReportController::class, 'show'])->name('report.show');
});

/*
 * গ্রাহক পোর্টাল — বাইরের মানুষ, তাই `auth` গ্রুপের বাইরে।
 *
 * উপসর্গ `sales` নয়, `portal`: ডিলার "বিক্রয় মডিউল" চেনেন না, তিনি
 * চেনেন "আমার পাতা"। আর ঠিকানাটা ছোট হলে ফোনে লিখতেও সহজ।
 */
Route::prefix('portal')->name('portal.')->group(function () {
    Route::middleware('guest:portal')->group(function () {
        Route::get('/login', [PortalController::class, 'showLogin'])->name('login');

        /*
         * বাইরের দরজায় একটা তালা — মিনিটে পাঁচবার।
         *
         * ── কেন কর্মীর লগইনে যা লাগে না, এখানে লাগে ─────────────────
         * কর্মীর লগইন অফিসের ভেতরের ব্যাপার। এই পাতাটা ইন্টারনেটে
         * খোলা, আর লগইনের নামটা গোপন কিছু নয় — কোডটা প্রতিটা বিলের
         * উপরে ছাপা থাকে। CUS-0001 থেকে CUS-9999 পর্যন্ত ধরে ধরে
         * চেষ্টা করাটা তাই আন্দাজ নয়, তালিকা মিলিয়ে দেখা।
         *
         * সীমা ছাড়া একটা স্ক্রিপ্ট রাতভর চললে দুর্বল পাসওয়ার্ডওয়ালা
         * ডিলারের খাতা খুলে যেত, আর কোনো চিহ্নও থাকত না।
         */
        Route::post('/login', [PortalController::class, 'login'])
            ->middleware('throttle:5,1')->name('login.attempt');
    });

    Route::middleware(['auth:portal', EnsurePortalStillOpen::class])->group(function () {
        Route::get('/', [PortalController::class, 'home'])->name('home');

        /*
         * নিজের খতিয়ান — "আমার কত বাকি" প্রশ্নের পূর্ণ উত্তর।
         *
         * ⓘ কোনো `{customer}` প্যারামিটার নেই, আর সেটাই এখানকার
         * নিরাপত্তা: ডিলার আসে সেশন থেকে ([[PortalController::dealer()]]),
         * ঠিকানা থেকে নয়। URL-এ একটা আইডি থাকলে একদিন কেউ সংখ্যাটা
         * বদলে অন্যের খাতা দেখে ফেলতেন।
         */
        Route::get('/ledger', [PortalController::class, 'ledger'])->name('ledger');
        Route::post('/logout', [PortalController::class, 'logout'])->name('logout');
        Route::get('/claims/new', [PortalController::class, 'showClaim'])->name('claim.create');
        Route::post('/claims', [PortalController::class, 'storeClaim'])->name('claim.store');
        Route::get('/claims/{claim}', [PortalController::class, 'showOwnClaim'])
            ->whereNumber('claim')->name('claim.show');
    });
});
