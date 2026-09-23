<?php

declare(strict_types=1);

use App\Modules\Inventory\Http\Controllers\BatchController;
use App\Modules\Inventory\Http\Controllers\LabelController;
use App\Modules\Inventory\Http\Controllers\OpeningStockController;
use App\Modules\Inventory\Http\Controllers\StrandedStockController;
use App\Modules\Inventory\Http\Controllers\ProductController;
use App\Modules\Inventory\Http\Controllers\StockAnalysisController;
use App\Modules\Inventory\Http\Controllers\StockController;
use App\Modules\Inventory\Http\Controllers\StockOverviewController;
use App\Modules\Inventory\Http\Controllers\StockPlacementController;
use App\Modules\Inventory\Http\Controllers\StockPrintController;
use App\Modules\Inventory\Http\Controllers\StockReportController;
use App\Modules\Inventory\Http\Controllers\StockTransferController;
use App\Modules\Inventory\Http\Controllers\StorageLocationController;
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
        /*
         * গুদামের নিজের পাতা — ২০ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ `/create`-এর নিচে, কারণ পথটা সংখ্যায় বাঁধা (`whereNumber`) —
         * নাহলে "create" কে একটা গুদামের আইডি ভেবে বাইন্ডিং ৪০৪ দিত।
         */
        Route::get('/{warehouse}', [WarehouseController::class, 'show'])->whereNumber('warehouse')->name('show');
        Route::get('/{warehouse}/edit', [WarehouseController::class, 'edit'])->whereNumber('warehouse')->name('edit');
        Route::put('/{warehouse}', [WarehouseController::class, 'update'])->whereNumber('warehouse')->name('update');
        Route::delete('/{warehouse}', [WarehouseController::class, 'destroy'])->whereNumber('warehouse')->name('destroy');

        // ফেরার পথ — নিষ্ক্রিয় করা একমুখী দরজা হতে পারে না
        // (WarehouseService::activate-এ কারণ)
        Route::post('/{warehouse}/activate', [WarehouseController::class, 'activate'])
            ->whereNumber('warehouse')->name('activate');

        /*
         * গুদামের ভিতরের জায়গা — ব্লক ▸ র‍্যাক ▸ শেলফ।
         *
         * ⭐ ঠিকানাটা গুদামের **নিচে**, আলাদা কোনো শাখায় নয়। একটা তাক
         * গুদাম ছাড়া কিছুই নয়, আর ঠিকানাটা সেটাই বলে — ভুল গুদামের
         * তাক খোলার কোনো পথই থাকে না।
         */
        Route::prefix('/{warehouse}/places')->name('place.')->group(function () {
            Route::get('/', [StorageLocationController::class, 'index'])
                ->whereNumber('warehouse')->name('index');
            Route::post('/', [StorageLocationController::class, 'store'])
                ->whereNumber('warehouse')->name('store');
            Route::put('/{place}', [StorageLocationController::class, 'update'])
                ->whereNumber(['warehouse', 'place'])->name('update');
            Route::delete('/{place}', [StorageLocationController::class, 'destroy'])
                ->whereNumber(['warehouse', 'place'])->name('destroy');
        });
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

        // মরা · ধীর · দ্রুত চলা মাল — ড্যাশবোর্ডের সংখ্যার ড্রিল-ডাউন তালিকা
        Route::get('/movement', [StockAnalysisController::class, 'movement'])->name('movement');

        // স্টকের বয়স — কোন বাকেটে কত টাকা আটকে (FIFO স্তর ধরে)
        Route::get('/age', [StockAnalysisController::class, 'age'])->name('age');

        Route::get('/', [StockController::class, 'index'])->name('index');

        /*
         * মাল বুঝে নেওয়া — গাড়ি থেকে নামা আর গুদামে ঢোকা এক নয়।
         *
         * ক্রয় বা রিসিভের মাল প্রথমে "বসেনি" ঘরে বসে, আর এই পর্দা
         * দিয়ে তাকে তোলা হয়। ⓘ ততক্ষণ মালটা বিক্রয়যোগ্য নয় — মালিকের
         * নিয়ম, ৪ সেপ্টেম্বর ২০২৬।
         */
        Route::get('/placement', [StockPlacementController::class, 'index'])->name('placement');
        Route::post('/placement', [StockPlacementController::class, 'store'])->name('placement.store');

        Route::get('/adjust', [StockController::class, 'adjust'])->name('adjust');
        Route::post('/adjust', [StockController::class, 'storeAdjustment'])->name('adjust.store');

        // বিক্রি ছাড়া মাল বেরোনো — আপ্যায়ন, উপহার, মালিকের ব্যবহার, নমুনা
        Route::get('/issue', [StockController::class, 'issue'])->name('issue');
        Route::post('/issue', [StockController::class, 'storeIssue'])->name('issue.store');

        // খোলা মজুদ — পুরনো খাতা থেকে আসার দিনের কাজ, সমন্বয় নয়
        Route::get('/opening', [OpeningStockController::class, 'index'])->name('opening');
        Route::post('/opening', [OpeningStockController::class, 'store'])->name('opening.store');

        /*
         * লট বসানো — মাল আনা নয়, নাম বসানো।
         *
         * ⓘ লট ধরা চালু করার আগেকার মজুদ বিক্রয়ের বাছাইয়ে আসে না —
         * সেই মালগুলোর লট এখান থেকে বসানো হয়। ⚠️ খোলা মজুদের পর্দায়
         * নয়, কারণ ওটা মাল **আনে** — আর এই মাল আগে থেকেই তাকে।
         */
        Route::get('/lot', [StrandedStockController::class, 'index'])->name('lot');
        Route::post('/lot', [StrandedStockController::class, 'store'])->name('lot.store');
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
     * ⓘ রেসিপির রুটগুলো এখানে ছিল — ১৫ সেপ্টেম্বর ২০২৬-এ রেস্তোরাঁয় গেছে।
     *
     * ── ⚠️ আর এখানে যে কারণটা লেখা ছিল, সেটা এখন পুরনো ─────────────
     * লেখা ছিল: *"রেসিপি বিক্রির কথা নয়, স্টকের কথা… বিক্রয়ে রাখলে
     * উৎপাদনকে বিক্রয়ের উপর নির্ভর করতে হত।"*
     *
     * ⭐ যুক্তিটা তখন ঠিক ছিল, আর আজও ঠিক — কিন্তু উত্তরটা ভুল ছিল।
     * রেসিপি **রেস্তোরাঁর** কথা, আর উৎপাদনও তাই; দুইটাই এখন একসাথে
     * ওখানে। মালিকের সিদ্ধান্ত: *"রেসিপি রান্নাঘরে যাবে।"*
     *
     * ── ⛔ যে দেয়ালটা এতদিন আটকে রেখেছিল, আর যেভাবে ভাঙা হলো ────────
     * [[App\Modules\Sales\Services\SalesInvoiceService]] রেসিপি পড়ে
     * (বিক্রি হলে উপকরণ কাটতে হয়), আর বিক্রয় রেস্তোরাঁর উপর দাঁড়াতে
     * পারে না। ⓘ তাই মাঝখানে কোরের একটা চুক্তি বসেছে —
     * [[App\Core\Contracts\RecipeBook]] — আর বিক্রয় এখন কেবল সেটাই চেনে।
     */

    /*
     * রান্নাঘরের বোর্ড — এখন আর কী কী বানানো যাবে।
     *
     * `refresh` একই প্রশ্নের JSON উত্তর, আর একই সেশন ও একই অনুমতিতে
     * চলে: একটা পাতা নিজের সংখ্যাটা আবার আনার জন্য bearer টোকেন
     * বানানো মানে XSS-এর সামনে একটা সত্যিকারের টোকেন রেখে দেওয়া,
     * কোনো লাভ ছাড়াই।
     */

    /*
     * ⓘ রান্নার রুটগুলো এখানে ছিল — ১৫ সেপ্টেম্বর ২০২৬-এ রেস্তোরাঁয় গেছে।
     *
     * মালিক ছবিতে দাগিয়ে বলেছেন *"cooking, food cost — egolo Restaurant
     * modiule zawar kotha"*। ⭐ কথাটা ঠিক: রান্না একটা রেস্তোরাঁর ঘটনা,
     * মজুদের নয়। মজুদ কেবল বলে কী ঢুকল আর কী বেরোল।
     *
     * ⚠️ রেসিপি কিন্তু **এখানেই থেকে গেছে**, আর সেটা ইচ্ছাকৃত —
     * [[App\Modules\Sales\Services\SalesInvoiceService]] রেসিপি পড়ে
     * (বিক্রি হলে উপকরণ কাটতে হয়)। রেসিপি রেস্তোরাঁয় সরালে বিক্রয়কে
     * রেস্তোরাঁ চিনতে হত, আর [[BoundariesTest]] ঠিকই লাল হত — বিক্রয়ের
     * `depends_on`-এ রেস্তোরাঁ নেই, থাকার কথাও নয়।
     */

    Route::get('/reports/{slug}', [StockReportController::class, 'show'])->name('report.show');
});
