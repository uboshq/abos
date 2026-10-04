<?php

declare(strict_types=1);

use App\Http\Middleware\EnsurePortalStillOpen;
use App\Modules\Sales\Http\Controllers\ChallanSampleController;
use App\Modules\Sales\Http\Controllers\CollectionController;
use App\Modules\Sales\Http\Controllers\CommissionClaimController;
use App\Modules\Sales\Http\Controllers\DeliveryChallanController;
use App\Modules\Sales\Http\Controllers\DepositClaimController;
use App\Modules\Sales\Http\Controllers\DirectSaleController;
use App\Modules\Sales\Http\Controllers\InvoiceSampleController;
use App\Modules\Sales\Http\Controllers\LotTraceController;
use App\Modules\Sales\Http\Controllers\OrderSampleController;
use App\Modules\Sales\Http\Controllers\PortalController;
use App\Modules\Sales\Http\Controllers\PosController;
use App\Modules\Sales\Http\Controllers\PrintQueueController;
use App\Modules\Sales\Http\Controllers\MarginReportController;
use App\Modules\Sales\Http\Controllers\GatePassController;
use App\Modules\Sales\Http\Controllers\LoadingSheetController;
use App\Modules\Sales\Http\Controllers\ReceiptSampleController;
use App\Modules\Sales\Http\Controllers\SalesInvoiceController;
use App\Modules\Sales\Http\Controllers\DeliveryScanController;
use App\Modules\Sales\Http\Controllers\DeliveryStageController;
use App\Modules\Sales\Http\Controllers\SalesOrderController;
use App\Modules\Sales\Http\Controllers\SalesPrintController;
use App\Modules\Sales\Http\Controllers\SalesReportController;
use App\Modules\Sales\Http\Controllers\SalesReturnController;
use App\Modules\Sales\Http\Controllers\SalesReturnReasonReportController;
use App\Modules\Sales\Http\Controllers\SalesTargetController;
use App\Modules\Sales\Http\Controllers\SchemeController;
use App\Modules\Sales\Http\Controllers\ShiftController;
use App\Modules\Sales\Http\Controllers\ShipmentController;
use Illuminate\Support\Facades\Route;

/*
 * Sales মডিউলের রুট — ModuleServiceProvider নিজে নিবন্ধন করে (সেকশন ১৯.৩)।
 * স্থির পথ {model}-এর আগে, আর {model} সংখ্যায় বাঁধা।
 */

/*
 * ⭐ কাগজের QR-এর দরজা — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"ekta qr add korbe zate mobile scane
 * korei delivery dap gulo complate …"* আর *"ekoi code dilar scane kore tar hisab r invoice dekte pare"*।
 *
 * ⓘ লগইনের বাইরে, কারণ একই QR কর্মী আর ডিলার দুজনেই স্ক্যান করেন — কে এসেছেন তা controller দেখে
 * ঠিক করে কোন দরজায় পাঠাবে ([[DeliveryScanController::open()]])। ⛔ এই পাতা নিজে কিছু দেখায় না,
 * কিছু বদলায় না; `public_id` কেবল UUID-র আকারে মানা হয়।
 */
Route::get('/scan/{publicId}', [DeliveryScanController::class, 'open'])->where('publicId', '[0-9a-fA-F-]{36}')->name('scan');

/* ⭐ সই-করা QR — ছাপা কাগজের নতুন QR এখানে আসে, তারপর উপরের দরজায় (১ অক্টোবর ২০২৬, [[QrScanController]]) */
Route::get('/q/{token}', [\App\Modules\Sales\Http\Controllers\QrScanController::class, 'open'])->where('token', '[A-Za-z0-9_-]{38}')->name('qr');

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
    /*
     * ⭐ মেনুতে আগে বসানো পর্দা — উদ্ধৃতি ও বিক্রয় আদেশ (মালিক, ২৮ সেপ্টেম্বর ২০২৬)।
     * ⓘ নাম তালিকার বাইরে হলে ৪০৪ ([[PlannedScreenController::SCREENS]])।
     */
    /*
     * ⭐ মূল্য তালিকা — পণ্যের বিক্রয়-দাম, সারি থেকেই বদলানো (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।
     * ⓘ দেখা sales.order.view, বদলানো inventory.product.update — কন্ট্রোলারের middleware()-এ।
     */
    Route::prefix('price-list')->name('price_list.')->group(function () {
        Route::get('/', [\App\Modules\Sales\Http\Controllers\PriceListController::class, 'index'])->name('index');
        Route::put('/{product}', [\App\Modules\Sales\Http\Controllers\PriceListController::class, 'update'])
            ->whereNumber('product')->name('update');
        Route::get('/{product}/history', [\App\Modules\Sales\Http\Controllers\PriceListController::class, 'history'])
            ->whereNumber('product')->name('history');
    });

    Route::get('/planned/{screen}', [\App\Modules\Sales\Http\Controllers\PlannedScreenController::class, 'show'])
        ->name('planned');

    /*
     * ⭐ ডেলিভারি অর্ডার — প্রতিটা বিক্রির চালান, ধাপের ট্যাবে (মালিকের সিদ্ধান্ত, ২৮ সেপ্টেম্বর
     * ২০২৬)। ⓘ কেবল দেখা, তাই একটাই GET।
     */
    Route::get('/do', [\App\Modules\Sales\Http\Controllers\DeliveryOrderController::class, 'index'])->name('do.index');

    /*
     * ⭐ আসল DO কাগজের ডেস্ক — "DO Create & List" (মালিক, ২ অক্টোবর ২০২৬; [[DeliveryOrderDeskController]])।
     * ⓘ মেনুর "ডেলিভারি অর্ডার (DO)" ভাঁজ এখানে আসে; উপরের পুরনো `/do` কাউন্টারের চালানের ধাপ।
     */
    Route::prefix('delivery-orders')->name('delivery_order.')->group(function () {
        Route::get('/', [\App\Modules\Sales\Http\Controllers\DeliveryOrderDeskController::class, 'index'])->name('index');
        Route::get('/create', [\App\Modules\Sales\Http\Controllers\DeliveryOrderDeskController::class, 'create'])->name('create');
        Route::post('/', [\App\Modules\Sales\Http\Controllers\DeliveryOrderDeskController::class, 'store'])->name('store');
        Route::get('/{order}', [\App\Modules\Sales\Http\Controllers\DeliveryOrderDeskController::class, 'show'])->whereNumber('order')->name('show');
        Route::post('/{order}/submit', [\App\Modules\Sales\Http\Controllers\DeliveryOrderDeskController::class, 'submit'])->whereNumber('order')->name('submit');
        Route::post('/{order}/quantities', [\App\Modules\Sales\Http\Controllers\DeliveryOrderDeskController::class, 'quantities'])->whereNumber('order')->name('quantities');
    });

    Route::prefix('direct')->name('direct.')->group(function () {
        Route::get('/', [DirectSaleController::class, 'create'])->name('create');
        Route::post('/', [DirectSaleController::class, 'store'])->name('store');
        // ⭐ নিশ্চিতের আগে সারাংশ — পপ-আপের ভিতর ([[DirectSaleOverviewController]], ৪ অক্টোবর ২০২৬)
        Route::post('/overview', \App\Modules\Sales\Http\Controllers\DirectSaleOverviewController::class)->name('overview');

        /*
         * ⭐ রাখা খসড়া বাতিল — মালিকের নির্দেশ, ২৬ সেপ্টেম্বর ২০২৬: খোলা খসড়া
         * থাকলে নতুন বিল নয়; আগে "conf … batil … edit"। ⓘ চাবিটা কাউন্টারের
         * নিজের (নিয়ামকের `can:sales.challan.create`) — খসড়াটা ঐ কাউন্টারেরই।
         */
        Route::post('/drafts/{invoice}/discard', [DirectSaleController::class, 'discard'])->name('discard');
        // ⭐ বিল বাতিল (Ctrl+X) — পাকা হওয়ার আগে, কারণসহ, অডিটে (মালিক, ৪ অক্টোবর ২০২৬)
        Route::post('/void', [DirectSaleController::class, 'void'])->name('void');

        /*
         * ⭐ রাখা খসড়ার তালিকা — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"সরাসরি
         * বিক্রয় menu er pore ro ekta menu … খসড়া"*। ⓘ কেবল দেখা আর খোলা —
         * খসড়া বানানো ও পাকা করা কাউন্টারেই, তাই একই চাবি।
         */
        Route::get('/drafts', [DirectSaleController::class, 'drafts'])->name('drafts');
        // ⓘ খসড়া সরিয়ে রাখা ও ফেরানো — তালিকার বোতাম (মালিক, ২৮ সেপ্টেম্বর ২০২৬)
        Route::post('/drafts/{invoice}/pause', [DirectSaleController::class, 'pauseDraft'])->name('draft_pause');
        Route::post('/drafts/{invoice}/resume', [DirectSaleController::class, 'resumeDraft'])->name('draft_resume');
        // ⓘ সইয়ের অপেক্ষা থেকে খসড়ায় ফেরানো — কেবল যিনি পাঠিয়েছেন (মালিক, ২৮ সেপ্টেম্বর ২০২৬)
        Route::post('/drafts/{invoice}/withdraw', [DirectSaleController::class, 'withdrawHeld'])->name('draft_withdraw');

        /*
         * এই মালে কতটা ফ্রি — সারি যোগ করার আগে জিজ্ঞাসা।
         *
         * ⓘ এটা প্রশ্ন, আদেশ নয় — ⚠️ কিছু বসায় না, কেবল সংখ্যাটা
         * বলে। দেয়ালটা [[DirectSaleService]]-এই থাকে।
         */
        Route::get('/free-allowed', [DirectSaleController::class, 'freeAllowed'])->name('free_allowed');

        /*
         * ⭐ ডিপোর যাচাই — হিসাবে অনুমোদিত DO, "যাচাই করে বিক্রয়ে খুলুন" (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ)।
         * ⓘ কেবল দেখা; খোলা কাউন্টারেই (`create?source=do`)। চাবি কাউন্টারের ([[DepotCheckController]])।
         */
        Route::get('/depot-check', [\App\Modules\Sales\Http\Controllers\DepotCheckController::class, 'index'])->name('depot_check');
        Route::post('/depot-check/open', [\App\Modules\Sales\Http\Controllers\DepotCheckController::class, 'open'])->name('depot_check.open');
    });

    Route::prefix('orders')->name('order.')->group(function () {
        // ⭐ পাঠানোর আগের হিসাব আর লাইনের অফার — JSON (১ অক্টোবর ২০২৬, [[OrderStandingController]])
        Route::get('/standing/{customer}', [\App\Modules\Sales\Http\Controllers\OrderStandingController::class, 'standing'])->name('standing');
        Route::post('/offers', [\App\Modules\Sales\Http\Controllers\OrderStandingController::class, 'offers'])->name('offers');
        Route::get('/', [SalesOrderController::class, 'index'])->name('index');
        /*
         * ⭐ আদেশ কোথায় দাঁড়িয়ে — মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬।
         * ⓘ `{order}`-এর আগে, কারণ "track" একটা স্থির পথ।
         */
        Route::get('/track', [SalesOrderController::class, 'track'])->name('track');
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
     * তালিকাই প্রধান পর্দা, আর সিদ্ধান্ত সারি থেকেই। বসানোটা এখন
     * নিজের পাতায় (`/create`) — তালিকার নিচে গোঁজা ফর্মটা সরানো হয়েছে,
     * নকল করা হয়নি: দুইটা পথ থাকলে একদিন একটা বদলাত, অন্যটা নয়।
     * `show` নেই — একটা দাবির পুরো কথাটা সারিতেই ধরে।
     */
    /*
     * গ্রাহকদের তোলা জমার দাবি — ডিপোর দিক।
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
        // ⭐ স্লিপসহ জমার অনুরোধ — কর্মীর হাতে, আর হিসাবরক্ষকের স্লিপ দেখা (১ অক্টোবর ২০২৬, [[DepositRequestController]])
        Route::get('/request/new', [\App\Modules\Sales\Http\Controllers\DepositRequestController::class, 'create'])->name('request.create');
        Route::post('/request', [\App\Modules\Sales\Http\Controllers\DepositRequestController::class, 'store'])->name('request.store');
        Route::get('/{claim}/slip', [\App\Modules\Sales\Http\Controllers\DepositRequestController::class, 'slip'])
            ->whereNumber('claim')->name('slip');
    });

    /*
     * স্কিম — কমিশনের নিয়ম যেখানে লেখা থাকে।
     *
     * ধাপগুলো স্কিমের নিজের পাতায় বসে ও মুছে, তাই ওদের রুট স্কিমের
     * নিচেই — নাহলে একটা ধাপ কোন স্কিমের তা ঠিকানা দেখে বলা যেত না।
     */
    Route::prefix('schemes')->name('scheme.')->group(function () {
        Route::get('/', [SchemeController::class, 'index'])->name('index');

        // বসানোর পর্দা — `/{scheme}`-এর আগে, নাহলে "create" একটা id ভেবে ৪০৪
        Route::get('/create', [SchemeController::class, 'create'])->name('create');
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

        // বসানোর পর্দা — `/{claim}`-এর আগে, নাহলে "create" একটা id ভেবে ৪০৪
        Route::get('/create', [CommissionClaimController::class, 'create'])->name('create');
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

    // ⭐ ডিলারের মাসিক আদায়ের লক্ষ্য — বিলের "টার্গেট রিমাইন্ডার" (মালিক, ৩ অক্টোবর ২০২৬)
    Route::prefix('dealer-targets')->name('customer_target.')->group(function () {
        Route::get('/', [\App\Modules\Sales\Http\Controllers\CustomerTargetController::class, 'index'])->name('index');
        Route::post('/', [\App\Modules\Sales\Http\Controllers\CustomerTargetController::class, 'store'])->name('store');
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

    /*
     * ⭐ ডেলিভারির ধাপ — NEXUS §২১–২২। ⓘ ধাপ মাল নড়ায় না; নড়ায় চালান আর
     * ট্রিপ, ধাপ কেবল বলে কোথায় আছে ([[DeliveryStageService]])।
     */
    // ⭐ ডেলিভারি ট্র্যাকিং — প্রতিটা বিক্রি কোথায়, কে কখন (মালিক, ২ অক্টোবর ২০২৬; ফোনেরই হিসাব, [[SaleTracking]])
    Route::get('/tracking', [\App\Modules\Sales\Http\Controllers\SaleTrackingController::class, 'page'])->name('tracking.index');
    Route::get('/tracking/{kind}/{id}', [\App\Modules\Sales\Http\Controllers\SaleTrackingController::class, 'story'])
        ->where('kind', 'challan|order')->whereUuid('id')->name('tracking.show');

    Route::prefix('deliveries')->name('delivery.')->group(function () {
        /* ⭐ QR থেকে কর্মী — চাবি controller-এ (can:sales.delivery.view); `{challan}`-এর আগে */
        Route::get('/scan/{publicId}', [DeliveryScanController::class, 'staff'])->where('publicId', '[0-9a-fA-F-]{36}')->name('scan');
        Route::get('/', [DeliveryStageController::class, 'index'])->name('index');
        Route::get('/{challan}', [DeliveryStageController::class, 'show'])->whereNumber('challan')->name('show');
        Route::post('/{challan}/stage', [DeliveryStageController::class, 'move'])->whereNumber('challan')->name('move');
    });

    // ⭐ বাতিল-ইনভয়েসের পাতা আর ছাপা (৪ অক্টোবর ২০২৬)
    Route::get('/cancellations/{cancellation}', [\App\Modules\Sales\Http\Controllers\SalesInvoiceCancellationController::class, 'show'])
        ->whereNumber('cancellation')->name('cancellation.show');
    Route::get('/cancellations/{cancellation}/print', [SalesPrintController::class, 'cancellation'])
        ->whereNumber('cancellation')->name('cancellation.print');

    Route::prefix('invoices')->name('invoice.')->group(function () {
        Route::get('/', [SalesInvoiceController::class, 'index'])->name('index');
        Route::get('/create', [SalesInvoiceController::class, 'create'])->name('create');
        Route::post('/', [SalesInvoiceController::class, 'store'])->name('store');
        Route::get('/{invoice}', [SalesInvoiceController::class, 'show'])->whereNumber('invoice')->name('show');
        Route::get('/{invoice}/edit', [SalesInvoiceController::class, 'edit'])->whereNumber('invoice')->name('edit');
        Route::put('/{invoice}', [SalesInvoiceController::class, 'update'])->whereNumber('invoice')->name('update');
        Route::post('/{invoice}/confirm', [SalesInvoiceController::class, 'confirm'])->whereNumber('invoice')->name('confirm');
        Route::post('/{invoice}/cancel', [SalesInvoiceController::class, 'cancel'])->whereNumber('invoice')->name('cancel');
        // ⭐ বাতিল-ইনভয়েস — পাকা ইনভয়েসের পুরো উল্টো কাগজ, নিজের নম্বরে (মালিক, ৪ অক্টোবর ২০২৬)
        Route::post('/{invoice}/cancellation', [\App\Modules\Sales\Http\Controllers\SalesInvoiceCancellationController::class, 'store'])
            ->whereNumber('invoice')->name('cancellation');
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

    /*
     * ⭐ "Set Invoice Information"-এর নমুনা বিল — বানানো তথ্যে, কারও আসল বিল নয়
     * ([[InvoiceSampleController]])। ⓘ ছাপার নিয়ন্ত্রণের পাতা `Route::has()` দিয়ে এটা খোঁজে।
     */
    Route::get('/print/invoice-sample', [InvoiceSampleController::class, 'show'])->name('invoice_sample');
    Route::get('/print/challan-sample', [ChallanSampleController::class, 'show'])->name('challan_sample');
    Route::get('/print/order-sample', [OrderSampleController::class, 'show'])->name('order_sample');
    Route::get('/print/receipt-sample', [ReceiptSampleController::class, 'show'])->name('receipt_sample');

    // ⭐ "মাল কীভাবে যাবে" — নিশ্চিতের পরে, ছাপার আগে; ঠিকানার শেষে নম্বর, তাই তালিকা থেকে পপআপে খোলে
    Route::get('/transport/invoice/{invoice}', [\App\Modules\Sales\Http\Controllers\ChallanTransportController::class, 'forInvoice'])
        ->whereNumber('invoice')->name('invoice.transport');
    Route::get('/transport/challan/{challan}', [\App\Modules\Sales\Http\Controllers\ChallanTransportController::class, 'forChallan'])
        ->whereNumber('challan')->name('challan.transport');
    Route::put('/transport/challan/{challan}', [\App\Modules\Sales\Http\Controllers\ChallanTransportController::class, 'update'])
        ->whereNumber('challan')->name('challan.transport.update');

    Route::prefix('print')->name('print.')->group(function () {
        Route::get('/invoice/{invoice}', [SalesPrintController::class, 'invoice'])
            ->whereNumber('invoice')->name('invoice');
        Route::get('/invoice/{invoice}/draft', [SalesPrintController::class, 'draft'])
            ->whereNumber('invoice')->name('draft');
        Route::get('/challan/{challan}', [SalesPrintController::class, 'challan'])
            ->whereNumber('challan')->name('challan')
            // ⭐ মাল কীভাবে যাবে, না বলে চালান ছাপা নয় (মালিক, ১ অক্টোবর ২০২৬; [[RequireTransportBeforePrint]])
            ->middleware(\App\Modules\Sales\Http\Middleware\RequireTransportBeforePrint::class);
        Route::get('/challan/{challan}/gatepass', [SalesPrintController::class, 'gatepass'])
            ->whereNumber('challan')->name('gatepass')->middleware(\App\Modules\Sales\Http\Middleware\RequireTransportBeforePrint::class);
        // ⭐ গেট পাস — নিজের কাগজ, রওনার মুহূর্তে তৈরি ([[GatePassService]])
        Route::get('/gate-pass/{gatePass}', [SalesPrintController::class, 'gatePassDocument'])
            ->whereNumber('gatePass')->name('gate_pass')->middleware(\App\Modules\Sales\Http\Middleware\RequireTransportBeforePrint::class);
        // ⭐ লোডিং শিট — ট্রিপ ধরে ([[LoadingSheetController]])
        Route::get('/loading-sheet/{shipment}', [SalesPrintController::class, 'loadingSheet'])
            ->whereNumber('shipment')->name('loading_sheet');
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

        // কারণ ধরে ফেরত (NEXUS §২৪) — নাম `*.report.show`, যাতে রিপোর্টের দুই পাহারা দরজাটা চেনে
        Route::get('/reports/{slug}', [SalesReturnReasonReportController::class, 'show'])->name('report.show');
        Route::post('/', [SalesReturnController::class, 'store'])->name('store');
        Route::get('/{return}', [SalesReturnController::class, 'show'])->whereNumber('return')->name('show');
        Route::get('/{return}/edit', [SalesReturnController::class, 'edit'])->whereNumber('return')->name('edit');
        Route::put('/{return}', [SalesReturnController::class, 'update'])->whereNumber('return')->name('update');
        Route::post('/{return}/confirm', [SalesReturnController::class, 'confirm'])->whereNumber('return')->name('confirm');
        Route::post('/{return}/cancel', [SalesReturnController::class, 'cancel'])->whereNumber('return')->name('cancel');
    });

    Route::get('/reports/{slug}', [SalesReportController::class, 'show'])->name('report.show');

    // ⭐ লোডিং শিট — খোলা ট্রিপ, শিট, আর "লোডিং নিশ্চিত" ([[LoadingSheetController]])
    Route::prefix('loading-sheets')->name('loading_sheet.')->group(function () {
        Route::get('/', [LoadingSheetController::class, 'index'])->name('index');
        Route::get('/{shipment}', [LoadingSheetController::class, 'show'])->whereNumber('shipment')->name('show');
        Route::post('/{shipment}/confirm', [LoadingSheetController::class, 'confirm'])->whereNumber('shipment')->name('confirm');
    });

    // ⭐ পরিবহন বরাদ্দ — নিশ্চিত চালান তিন ট্যাবে; সারির বোতাম চালানের পরিবহন-পপআপ খোলে ([[TransportAssignmentController]])
    // ⭐ ডেলিভারির মাপকাঠি — OTIF, আদেশ থেকে রওনা, দেরির তালিকা ([[DeliveryPerformanceController]])
    Route::get('/delivery-performance', [\App\Modules\Sales\Http\Controllers\DeliveryPerformanceController::class, 'index'])->name('delivery_performance.index');

    Route::get('/transport', [\App\Modules\Sales\Http\Controllers\TransportAssignmentController::class, 'index'])->name('transport.index');

    // ⭐ গেট পাস — তালিকা, দেখা, কারণসহ বাতিল; তৈরির দরজা নেই ([[GatePassController]])
    Route::prefix('gate-passes')->name('gate_pass.')->group(function () {
        Route::get('/', [GatePassController::class, 'index'])->name('index');
        Route::get('/{gatePass}', [GatePassController::class, 'show'])->whereNumber('gatePass')->name('show');
        Route::post('/{gatePass}/cancel', [GatePassController::class, 'cancel'])->whereNumber('gatePass')->name('cancel');
    });
    // ⓘ মার্জিনের রিপোর্ট — নিজের চাবি ([[MarginReportController]], NEXUS §৩২)
    Route::get('/margin/{slug}', [MarginReportController::class, 'show'])->name('margin.report.show');
});

/*
 * গ্রাহক পোর্টাল — বাইরের মানুষ, তাই `auth` গ্রুপের বাইরে।
 *
 * উপসর্গ `sales` নয়, `portal`: গ্রাহক "বিক্রয় মডিউল" চেনেন না, তিনি
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
         * গ্রাহকের খাতা খুলে যেত, আর কোনো চিহ্নও থাকত না।
         */
        Route::post('/login', [PortalController::class, 'login'])
            ->middleware('throttle:5,1,portal-login')->name('login.attempt');
    });

    Route::middleware(['auth:portal', EnsurePortalStillOpen::class])->group(function () {
        Route::get('/', [PortalController::class, 'home'])->name('home');

        /* ⭐ QR থেকে ডিলার — কেবল নিজের চালান ([[ScannedPaper::dealer()]]), অন্যেরটা ৪০৪ */
        Route::get('/scan/{publicId}', [DeliveryScanController::class, 'dealer'])->where('publicId', '[0-9a-fA-F-]{36}')->name('scan');
        Route::post('/scan/{publicId}/received', [DeliveryScanController::class, 'received'])->where('publicId', '[0-9a-fA-F-]{36}')->name('scan.received');

        /*
         * নিজের খতিয়ান — "আমার কত বাকি" প্রশ্নের পূর্ণ উত্তর।
         *
         * ⓘ কোনো `{customer}` প্যারামিটার নেই, আর সেটাই এখানকার
         * নিরাপত্তা: গ্রাহক আসে সেশন থেকে ([[CustomerPapers::customer()]]),
         * ঠিকানা থেকে নয়। URL-এ একটা আইডি থাকলে একদিন কেউ সংখ্যাটা
         * বদলে অন্যের খাতা দেখে ফেলতেন।
         */
        Route::get('/ledger', [PortalController::class, 'ledger'])->name('ledger');
        Route::post('/logout', [PortalController::class, 'logout'])->name('logout');
        Route::get('/claims/new', [PortalController::class, 'showClaim'])->name('claim.create');
        Route::post('/claims', [PortalController::class, 'storeClaim'])->name('claim.store');
        Route::get('/claims/{claim}', [PortalController::class, 'showOwnClaim'])
            ->whereNumber('claim')->name('claim.show');
        Route::get('/claims/{claim}/slip', [PortalController::class, 'ownClaimSlip'])
            ->whereNumber('claim')->name('claim.slip');
        // ⭐ নিজের বিক্রি কোথায় — ডেলিভারি ট্র্যাকিং ([[PortalController::tracking()]])
        // ⭐ ডিলারের নিজের DO — লেখা আর জমা ([[PortalDeliveryOrderController]], ২ অক্টোবর ২০২৬)
        Route::get('/do', [\App\Modules\Sales\Http\Controllers\PortalDeliveryOrderController::class, 'index'])->name('do.index');
        Route::get('/do/new', [\App\Modules\Sales\Http\Controllers\PortalDeliveryOrderController::class, 'create'])->name('do.create');
        Route::post('/do', [\App\Modules\Sales\Http\Controllers\PortalDeliveryOrderController::class, 'store'])->name('do.store');
        Route::get('/do/{id}', [\App\Modules\Sales\Http\Controllers\PortalDeliveryOrderController::class, 'show'])->whereUuid('id')->name('do.show');
        Route::post('/do/{id}/submit', [\App\Modules\Sales\Http\Controllers\PortalDeliveryOrderController::class, 'submit'])->whereUuid('id')->name('do.submit');
        Route::get('/tracking', [PortalController::class, 'tracking'])->name('tracking');
        Route::get('/tracking/{kind}/{id}', [PortalController::class, 'trackingStory'])
            ->where('kind', 'challan|order')->whereUuid('id')->name('tracking.show');
    });
});
