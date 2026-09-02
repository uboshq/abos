<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\BatchController;
use App\Modules\Inventory\Http\Controllers\LabelController;
use App\Modules\Inventory\Http\Controllers\OpeningStockController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\ProductionController;
use App\Modules\Inventory\Http\Controllers\RecipeController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockOverviewController;
use App\Modules\Inventory\Http\Controllers\StockPrintController;
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

    /*
     * লেবেল ছাপা — পণ্যের গায়ে সাঁটার কাগজ।
     *
     * GET, কারণ ছাপা কিছু বদলায় না আর নতুন ট্যাবে খোলা দরকার; বাছাটা
     * ঠিকানায় থাকে বলে একই বাছাই আবার ছাপতে হলে রিফ্রেশই যথেষ্ট।
     */
    Route::prefix('labels')->name('label.')->group(function () {
        Route::get('/', [LabelController::class, 'index'])->name('index');
        Route::get('/print', [LabelController::class, 'print'])->name('print');
    });

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

        // ফেরার পথ — নিষ্ক্রিয় করা একমুখী দরজা হতে পারে না
        // (WarehouseService::activate-এ কারণ)
        Route::post('/{warehouse}/activate', [WarehouseController::class, 'activate'])
            ->whereNumber('warehouse')->name('activate');
    });

    Route::prefix('stock')->name('stock.')->group(function () {
        /*
         * এক নজরে গুদাম — স্টকের নিজের সারাংশ।
         *
         * মডিউলের ড্যাশবোর্ড আলাদা (`dashboard/{module}`, কোরের
         * ইঞ্জিন থেকে)। এটা তার চেয়ে সংকীর্ণ ও গভীর: কেবল মজুদ,
         * কিন্তু গুদাম ধরে ছাঁকা যায়।
         */
        Route::get('/overview', [StockOverviewController::class, 'index'])->name('overview');

        Route::get('/', [StockController::class, 'index'])->name('index');
        Route::get('/adjust', [StockController::class, 'adjust'])->name('adjust');
        Route::post('/adjust', [StockController::class, 'storeAdjustment'])->name('adjust.store');

        // বিক্রি ছাড়া মাল বেরোনো — আপ্যায়ন, উপহার, মালিকের ব্যবহার, নমুনা
        Route::get('/issue', [StockController::class, 'issue'])->name('issue');
        Route::post('/issue', [StockController::class, 'storeIssue'])->name('issue.store');

        // খোলা মজুদ — পুরনো খাতা থেকে আসার দিনের কাজ, সমন্বয় নয়
        Route::get('/opening', [OpeningStockController::class, 'index'])->name('opening');
        Route::post('/opening', [OpeningStockController::class, 'store'])->name('opening.store');
        Route::post('/hold', [StockController::class, 'storeHold'])->name('hold');
        Route::post('/release', [StockController::class, 'storeRelease'])->name('release');
    });

    /*
     * লট — এখন কেবল দুইটা সংশোধন, নিজের কোনো তালিকা নেই।
     *
     * লটগুলো পণ্যের পাতায় দেখা যায়, আর ওখান থেকেই বদলানো হয়। আলাদা
     * তালিকা বানালে সেটা হত পণ্যহীন একরাশ নম্বরের পাতা — কেউ ওভাবে
     * লট খোঁজে না, সবাই খোঁজে "এই ওষুধের কোন লটটা"।
     */
    /*
     * লট — কেবল দুইটা সংশোধন।
     *
     * রিকলের পর্দাটা এখানে নেই, Sales-এ (`sales.lot.trace`): উত্তরটা
     * গ্রাহকের তালিকা, আর Inventory ঘোষণা করেছে সে Sales-এর উপর
     * দাঁড়ায় না।
     */
    Route::prefix('batches')->name('batch.')->group(function () {
        Route::put('/{batch}/price', [BatchController::class, 'reprice'])
            ->whereNumber('batch')->name('reprice');
        Route::put('/{batch}/expiry', [BatchController::class, 'correctExpiry'])
            ->whereNumber('batch')->name('expiry');
    });

    Route::prefix('transfers')->name('transfer.')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->name('index');
        Route::get('/create', [StockTransferController::class, 'create'])->name('create');
        Route::post('/', [StockTransferController::class, 'store'])->name('store');
        Route::get('/{transfer}', [StockTransferController::class, 'show'])->whereNumber('transfer')->name('show');

        /*
         * কাগজটা — মালের সাথে যায়, দুই প্রান্তে দুইজনের সই নিয়ে।
         *
         * `{transfer}` সংখ্যায় বাঁধা, তাই `print` অংশটা তার সাথে
         * সংঘাত করে না।
         */
        Route::get('/{transfer}/print', [StockPrintController::class, 'transfer'])
            ->whereNumber('transfer')->name('print');
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

    /*
     * রেসিপি — কোন খাবার কী দিয়ে তৈরি।
     *
     * ── কেন ইনভেন্টরির রুটে, বিক্রয়ের নয় ────────────────────────────
     * রেসিপি বিক্রির কথা নয়, **স্টকের** কথা। একই রেসিপি বিক্রিতে লাগে,
     * উৎপাদনে লাগে, খরচের রিপোর্টে লাগে। বিক্রয়ে রাখলে উৎপাদনকে
     * বিক্রয়ের উপর নির্ভর করতে হত — অথচ হাঁড়ি চড়ানোর সাথে বিক্রির
     * কোনো সম্পর্ক নেই।
     *
     * `whereNumber` — পণ্যের রুটে যে কারণে (উপরে লেখা): নাহলে
     * `/recipes/create`-কে একটা id ভেবে বাইন্ডিং ৪০৪ দিত।
     */
    Route::prefix('recipes')->name('recipe.')->group(function () {
        Route::get('/', [RecipeController::class, 'index'])->name('index');
        Route::get('/create', [RecipeController::class, 'create'])->name('create');
        Route::post('/', [RecipeController::class, 'store'])->name('store');
        Route::get('/{recipe}/edit', [RecipeController::class, 'edit'])
            ->whereNumber('recipe')->name('edit');
        Route::put('/{recipe}', [RecipeController::class, 'update'])
            ->whereNumber('recipe')->name('update');
        Route::delete('/{recipe}', [RecipeController::class, 'destroy'])
            ->whereNumber('recipe')->name('destroy');
        Route::post('/{recipe}/activate', [RecipeController::class, 'activate'])
            ->whereNumber('recipe')->name('activate');
    });

    /*
     * রান্নাঘরের বোর্ড — এখন আর কী কী বানানো যাবে।
     *
     * `refresh` একই প্রশ্নের JSON উত্তর, আর একই সেশন ও একই অনুমতিতে
     * চলে: একটা পাতা নিজের সংখ্যাটা আবার আনার জন্য bearer টোকেন
     * বানানো মানে XSS-এর সামনে একটা সত্যিকারের টোকেন রেখে দেওয়া,
     * কোনো লাভ ছাড়াই।
     */

    /*
     * রান্না — হাঁড়ির উৎপাদন।
     *
     * `confirm` আলাদা একটা POST, কারণ ওটাই আসল ঘটনা: খসড়া লেখা নিরীহ,
     * নিশ্চিত করা মানে গুদাম থেকে মাল বেরিয়ে যাওয়া।
     */
    Route::prefix('cookings')->name('production.')->group(function () {
        Route::get('/', [ProductionController::class, 'index'])->name('index');
        Route::get('/create', [ProductionController::class, 'create'])->name('create');
        Route::post('/', [ProductionController::class, 'store'])->name('store');
        Route::get('/{production}', [ProductionController::class, 'show'])
            ->whereNumber('production')->name('show');
        Route::post('/{production}/confirm', [ProductionController::class, 'confirm'])
            ->whereNumber('production')->name('confirm');
        Route::delete('/{production}', [ProductionController::class, 'destroy'])
            ->whereNumber('production')->name('destroy');
    });

    Route::get('/reports/{slug}', [StockReportController::class, 'show'])->name('report.show');
});
