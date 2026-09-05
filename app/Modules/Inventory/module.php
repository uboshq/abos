<?php

declare(strict_types=1);

use App\Models\UserDataScope;
use App\Modules\Inventory\Dashboard\InventoryDashboard;
use App\Modules\Inventory\Dashboard\InventoryWidgets;
use App\Modules\Inventory\Imports\OpeningStockImporter;
use App\Modules\Inventory\Imports\ProductImporter;
use App\Modules\Inventory\Models\CostLayer;
use App\Modules\Inventory\Models\CostLayerUse;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Reports\StockReports;

/**
 * Inventory — প্ল্যান Phase 6।
 *
 * এই মডিউলের কেন্দ্রে একটাই ধারণা: **স্টক একটা খতিয়ান, একটা সংখ্যা নয়।**
 *
 * প্রতিটা চলাচল একটা সারি — কী এল, কী গেল, কেন। "আছে কত" প্রশ্নের উত্তর
 * ওই সারিগুলোর যোগফল, আলাদা কোনো কলাম নয়। হিসাবের খাতায় ঠিক একই
 * সিদ্ধান্ত, একই কারণে: দুই কপি একদিন আলাদা হয়, আর তখন কোনটা সত্যি তা
 * বলার উপায় থাকে না। গুদামে ওই দিনটা আসে যখন কেউ বলে "খাতায় ৫০, তাকে ৪৭"।
 *
 * চারটা অবস্থা, আর ওদের মধ্যেকার অঙ্কটাই পুরো মডিউলের ভিত্তি:
 *
 *     Floor      = যা সত্যিই তাকে আছে
 *       − Reserved   = অনুমোদিত অর্ডারে ধরা
 *       − Hold       = আটকানো, কারণ সহ
 *       = Available  = যা বেচা যাবে
 *
 * Hold-এর কারণ তিন রকম, আর তৃতীয়টা ত্রুটি নয় — দাম বাড়ার অপেক্ষায় ধরে
 * রাখা একটা সিদ্ধান্ত। রিপোর্টে দুইটা মিলিয়ে ফেললে মালিক ভাবতেন তার
 * মালে সমস্যা, অথচ ওটা তার কৌশল।
 */
return [
    'code' => 'inventory',

    'name' => [
        'en' => 'Inventory',
        'bn' => 'মজুদ',
    ],

    'version' => '1.0.0',

    /*
     * সাইডবারে কোথায় — নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     *
     * ক্রয় আর বিক্রয়ের মাঝখানে দাঁড়ানো জিনিসটা, তাই মাঝখানেই।
     *
     * দলগুলোর তালিকা আর কেন এটা `depends_on`-এর থেকে আলাদা:
     * [[ModuleDefinition::NAV_SECTIONS]].
     */
    'nav' => ['section' => 'business', 'order' => 40],

    // একক, ভ্যাট ও কারণ-কোড মাস্টার ডাটা থেকে; মূল্যায়ন হিসাবের খাতায় যায়
    'depends_on' => ['master_data', 'accounts'],

    'menu' => [
        /*
         * ড্যাশবোর্ড — মডিউলের প্রথম সারি।
         *
         * ── কেন `route_params` লাগে ─────────────────────────────────
         * রুটটা কোরের একটাই (`dashboard/{module}`), তাই কোন মডিউলের
         * ড্যাশবোর্ড সেটা মডিউলকেই বলতে হয়। প্রতিটা মডিউলের আলাদা
         * রুট বানালে এই লাইনটা লাগত না, কিন্তু তখন বারোটা রুট ফাইলে
         * বারোটা প্রায়-একই লাইন থাকত।
         */
        'dashboard' => [
            ['label' => 'inventory::dashboard.title', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'inventory'], 'permission' => 'inventory.stock.view'],
        ],

        'master' => [
            ['label' => 'inventory::menu.products', 'route' => 'inventory.product.index', 'permission' => 'inventory.product.view'],

            /*
             * লেবেল ছাপা — পণ্যের ঠিক নিচে।
             *
             * পণ্যের তালিকা থেকেই লোকে এখানে আসেন: মাল ঢুকল, নতুন
             * পণ্য বসল, এবার গায়ে সাঁটার কাগজ চাই।
             */
            ['label' => 'inventory::label.title', 'route' => 'inventory.label.index',
                'permission' => 'inventory.product.view'],

            ['label' => 'inventory::menu.warehouses', 'route' => 'inventory.warehouse.index', 'permission' => 'inventory.warehouse.view'],

            /*
             * রেসিপি — মাস্টারে, লেনদেনে নয়।
             *
             * একটা রেসিপি লেনদেন নয়; ওটা একটা **নিয়ম**, যেটা একবার
             * লিখে রাখা হয় আর তারপর প্রতিটা বিক্রিতে কাজে লাগে —
             * পণ্যের একক বা ভ্যাটের হারের মতোই।
             *
             * লেনদেনের মেনুতে রাখলে লোকে রোজ ওখানে যেতেন, আর যেটা
             * বছরে দুইবার বদলায় সেটা রোজকার কাজের সাথে মিশে যেত।
             */
            ['label' => 'inventory::menu.recipes', 'route' => 'inventory.recipe.index',
                'permission' => 'inventory.recipe.view'],
        ],
        'transactions' => [
            /*
             * বসানোর সারিটা মজুদের **ঠিক আগে** — মালিকের সিদ্ধান্ত,
             * ৫ সেপ্টেম্বর ২০২৬।
             *
             * ── আগে এখানে উল্টোটা লেখা ছিল, আর যুক্তিটাও লেখা ছিল ────
             * *"মাল আসে → বুঝে নেওয়া হয় → তারপর গোনা-সমন্বয়"* — অর্থাৎ
             * মজুদের পরে। ⛔ ঐ যুক্তিটা **কাগজের ক্রম** ধরে সাজানো,
             * মানুষের দিনের ক্রম ধরে নয়।
             *
             * ⭐ গুদামের লোকের দিনটা শুরু হয় বসানো দিয়ে: গাড়ি এসেছে,
             * কার্টন নামাতে হবে। মজুদের পাতাটা তিনি খোলেন **তার পরে**,
             * আর প্রায়ই খোলেনই না। ⓘ যেটা রোজ সকালে লাগে সেটা উপরে —
             * তালিকার ক্রম অভ্যাসের ক্রম।
             *
             * ⚠️ আর একটা কারণ, যেটা এই রিপো নিজেই তৈরি করেছে: বসানোর
             * আগে মাল **বিক্রয়যোগ্যই নয়**। তাই মজুদের সংখ্যাটা দেখতে
             * যাওয়ার আগেই বসানোর কাজটা সারা থাকা দরকার, নাহলে সংখ্যাটা
             * কম দেখায় আর লোকে ভাবেন মাল আসেনি।
             */
            ['label' => 'inventory::menu.placement', 'route' => 'inventory.stock.placement',
                'permission' => 'inventory.stock.place'],
            ['label' => 'inventory::menu.stock', 'route' => 'inventory.stock.index', 'permission' => 'inventory.stock.view'],
            ['label' => 'inventory::menu.adjust', 'route' => 'inventory.stock.adjust', 'permission' => 'inventory.stock.adjust'],

            /*
             * সমন্বয়ের পাশে, কিন্তু আলাদা সারি।
             *
             * দুইটার প্রশ্ন আলাদা: সমন্বয় মানে "খাতা আর তাক মেলেনি",
             * ইস্যু মানে "জেনেশুনে দিয়ে দিলাম"। এক সারিতে রাখলে
             * আপ্যায়নের বিস্কুট মজুদ ঘাটতির রিপোর্টে গিয়ে বসত।
             */
            ['label' => 'inventory::menu.issue', 'route' => 'inventory.stock.issue', 'permission' => 'inventory.stock.adjust'],
            ['label' => 'inventory::menu.opening', 'route' => 'inventory.stock.opening', 'permission' => 'inventory.stock.opening'],
            ['label' => 'inventory::menu.transfers', 'route' => 'inventory.transfer.index', 'permission' => 'inventory.transfer.view'],

            /*
             * রান্না — লেনদেনে, রেসিপির পাশে নয়।
             *
             * রেসিপি একটা নিয়ম, বছরে দুইবার বদলায়। রান্না একটা ঘটনা,
             * রোজ সকালে ঘটে। এক মেনুতে রাখলে রোজকার কাজটা মাস্টার
             * ডাটার সাথে মিশে যেত।
             */
            ['label' => 'inventory::menu.production', 'route' => 'inventory.production.index',
                'permission' => 'inventory.production.view'],
        ],
        'reports' => [
            ['label' => 'inventory::menu.stock_ledger', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'stock-ledger'], 'permission' => 'inventory.report'],
            ['label' => 'inventory::menu.stock_summary', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'stock-summary'], 'permission' => 'inventory.report'],
            ['label' => 'inventory::menu.hold_report', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'hold'], 'permission' => 'inventory.report'],

            // মরা · ধীর · দ্রুত চলা মাল — ড্যাশবোর্ডের সংখ্যার ড্রিল-ডাউন
            ['label' => 'inventory::menu.stock_movement', 'route' => 'inventory.stock.movement',
                'permission' => 'inventory.report'],

            // স্টকের বয়স — কোন বাকেটে কত টাকা আটকে
            ['label' => 'inventory::menu.stock_age', 'route' => 'inventory.stock.age',
                'permission' => 'inventory.report'],

            /*
             * খাদ্য-খরচ — রেসিপির সুইচের পেছনে নয়, সবসময়ই।
             *
             * যে ব্যবসায় রেসিপি নেই তার তালিকা খালি আসবে, আর সেটাই
             * ঠিক উত্তর: "রান্না করা খাবার বিক্রি হয়নি"। মেয়াদের
             * রিপোর্টের মতো সুইচ লাগে না, কারণ রেসিপি বানানো নিজেই
             * একটা সিদ্ধান্ত — কেউ না বানালে সারিটা এমনিতেই নীরব।
             */
            ['label' => 'inventory::menu.food_cost', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'food-cost'], 'permission' => 'inventory.report'],

            /*
             * মেয়াদ ঘনিয়ে আসা লট — সুইচের পেছনে।
             *
             * যে ব্যবসায় ব্যাচ ধরা হয় না, তার কাছে এই সারিটা চিরকাল
             * খালি একটা পাতা খুলত, আর মেনুতে জায়গা নিত। ব্যাচের সুইচ
             * চালু থাকলেই কেবল দেখা যায় (নিয়ম ৭)।
             */
            ['label' => 'inventory::menu.expiring', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'expiring'], 'permission' => 'inventory.report',
                'setting' => 'inventory.batch_enabled'],

            // রিকলের পর্দাটা বিক্রয়ের মেনুতে — উত্তরটা গ্রাহকের তালিকা
        ],
    ],

    'permissions' => [
        'inventory.product.view',
        'inventory.product.create',
        'inventory.product.update',
        'inventory.product.delete',
        'inventory.warehouse.view',
        'inventory.warehouse.create',
        'inventory.warehouse.update',
        'inventory.warehouse.delete',
        'inventory.stock.view',
        'inventory.stock.adjust',
        'inventory.stock.hold',

        /*
         * মাল বুঝে নেওয়ার চাবি — দেখা বা সমন্বয়ের থেকে আলাদা।
         *
         * ── কেন নিজের চাবি ─────────────────────────────────────────
         * বসানো মানে **দায়িত্ব নেওয়া**: এরপর থেকে মালটা বিক্রয়যোগ্য,
         * আর গুদামের হিসাবে ঢুকে গেল। যিনি মজুদ দেখেন তাঁর সেটা করার
         * দরকার নেই, আর যিনি সমন্বয় করেন তিনিও আলাদা কাজ করেন —
         * সমন্বয় মানে "খাতা আর তাক মেলেনি", বসানো মানে "গাড়ির মাল
         * আমি বুঝে নিলাম"।
         *
         * ⚠️ `stock.view`-এর সাথে জুড়লে যে কেউ মজুদ দেখতে পারেন তিনিই
         * মাল বসিয়ে দিতে পারতেন, আর তখন "কে বুঝে নিল" প্রশ্নের কোনো
         * উত্তর থাকত না।
         */
        'inventory.stock.place',

        /*
         * ক্রয়মূল্য দেখার চাবি — পণ্য দেখার থেকে আলাদা।
         *
         * ── কেন আলাদা ──────────────────────────────────────────────
         * বিক্রয়কর্মীকে পণ্যের পাতা দেখতেই হবে, নাহলে তিনি বেচবেন কী
         * করে। কিন্তু **ক্রয়মূল্য তাঁর নয়** — ওটা জানা থাকলে
         * দরকষাকষিতে সেটাই ব্যবহার হয়, আর কোম্পানির মার্জিন বাইরে
         * চলে যায়।
         *
         * পণ্যের চাবির সাথে জুড়লে দুইটা খারাপ পথের একটা বেছে নিতে হত:
         * হয় তাঁর পণ্যের পাতা বন্ধ, নয় ক্রয়মূল্য ফাঁস।
         *
         * বিক্রয়ের `sales.cost.view` আলাদা রাখা হয়েছে ইচ্ছে করে: বিলের
         * গায়ে বিক্রীত পণ্যের ব্যয় দেখা আর মাস্টারে প্রতিটা পণ্যের
         * ক্রয়মূল্য দেখা — এক সিদ্ধান্ত নয়।
         */
        'inventory.cost.view',

        /*
         * রেসিপির নিজের চাবি — পণ্যের সাথে নয়।
         *
         * ── কেন আলাদা ──────────────────────────────────────────────
         * পণ্য দেখার অনুমতি অনেকের থাকে; রেসিপি **বদলানোর** অনুমতি
         * অল্প কয়েকজনের থাকা উচিত। একটা লাইনে "৫ কেজি চাল" বদলে
         * "৩ কেজি" করে দিলে প্রতিটা বিক্রিতে দুই কেজি চাল খাতায় থেকে
         * যাবে যা বাস্তবে নেই — আর ভুলটা নীরব।
         *
         * পণ্যের চাবির সাথে জুড়ে দিলে যে কেউ পণ্য সম্পাদনা করতে পারেন
         * তিনি রেসিপিও বদলাতে পারতেন, আর ওই দুইটা এক দায়িত্ব নয়।
         */
        'inventory.recipe.view',
        'inventory.recipe.create',
        'inventory.recipe.update',
        'inventory.recipe.delete',

        /*
         * রান্নার চাবি তিনটা, আর নিশ্চিত করাটা আলাদা।
         *
         * খসড়া লেখা নিরীহ — কিছুই নড়ে না। নিশ্চিত করা মানে গুদাম থেকে
         * মাল বেরিয়ে যাওয়া, আর সেটা মজুদ সমন্বয়ের সমান ক্ষমতা।
         *
         * এক চাবিতে রাখলে যিনি রোজ হাঁড়ির হিসাব লেখেন তিনিই স্টক
         * নামাতে পারতেন, আর ভুল সংখ্যা সাথে সাথেই খাতায় বসত।
         */
        'inventory.production.view',
        'inventory.production.create',
        'inventory.production.confirm',

        /*
         * লটের ছাপা দাম বদলানো — বিক্রয়ের অনুমতির সাথে নয়।
         *
         * MRP বাড়ে-কমে, তাই ঘরটা তালাবদ্ধ রাখা যায় না (মালিকের কথা,
         * ২০২৬-০৮-১৩)। কিন্তু ওই ঘরটা যিনি বদলাতে পারেন তিনি কার্যত
         * আইনি সিলিংটাই বদলাতে পারেন — আর কাউন্টারের লোকের হাতে সেটা
         * থাকা মানে "দাম বেশি চাইলে MRP বাড়িয়ে নাও" পথটা খোলা রাখা।
         */
        'inventory.batch.reprice',

        /*
         * খোলা মজুদের নিজের চাবি, সমন্বয়ের চাবি নয়।
         *
         * এই একটা পর্দাই সরাসরি অবশিষ্ট মুনাফায় টাকা বসাতে পারে, কোনো
         * কাগজ বা অনুমোদন ছাড়াই — কারণ শুরুর দিনের মালের আগে কোনো কাগজ
         * থাকেই না। তাই যিনি রোজ গুদাম গোনেন তাঁর হাতে এটা থাকা উচিত নয়;
         * এটা মালিক বা হিসাবরক্ষকের একবারের কাজ।
         */
        'inventory.stock.opening',

        /*
         * স্থানান্তরের চাবি চারটা, আর পাঠানো ও বুঝে নেওয়া আলাদা।
         *
         * পাঠান এক গুদামের লোক, বুঝে নেন অন্য গুদামের। একজনেই দুইটা
         * করতে পারলে "পাঠিয়েছি, পৌঁছেছে" লিখে দিয়ে মাল পথেই সরিয়ে
         * ফেলা যেত, আর কাগজে সবই মিলত।
         */
        'inventory.transfer.view',
        'inventory.transfer.create',
        'inventory.transfer.receive',
        'inventory.transfer.cancel',

        'inventory.report',
        'inventory.manage',
    ],

    /*
     * নতুন ইনস্টলে মজুদের অনুমতি কোন রোলের (§৫)। শুরুর সারি, তালা নয়।
     *
     * Warehouse: গুদামের পূর্ণ কাজ — মজুদ, ট্রান্সফার, পণ্য চেনা।
     * Field Sales: কেবল পণ্যের তালিকা (অর্ডার নিতে) — ⛔ মজুদ কখনো নয় (মালিকের নিয়ম)।
     * Manager: মজুদ ও রিপোর্ট দেখা।
     */
    'role_templates' => [
        'Warehouse' => [
            'inventory.stock.view', 'inventory.stock.adjust', 'inventory.stock.hold',

            /*
             * মাল বুঝে নেওয়া গুদামের লোকের কাজ — আর কারও নয়।
             *
             * ⚠️ এটা এখানে না থাকলে চাবিটা কেবল মালিকের কাছেই থাকত
             * (`Permission::all()`), আর গুদামের লোক রোজ সকালে মালিককে
             * ডেকে আনতেন। ⓘ তখন নিয়মটা এক সপ্তাহে "অসুবিধা" হয়ে যেত,
             * আর কেউ ওটা বন্ধ করার পথ খুঁজত।
             *
             * ⛔ Manager-এ দেওয়া হয়নি, ইচ্ছাকৃতভাবে: বসানো মানে
             * **দায়িত্ব নেওয়া**, আর দায়িত্বটা যিনি মাল গুনে বুঝে নেন
             * তাঁরই — দূর থেকে অনুমোদন করা কেউ নন।
             */
            'inventory.stock.place',

            'inventory.stock.opening',
            'inventory.transfer.view', 'inventory.transfer.create', 'inventory.transfer.receive',
            'inventory.product.view',
        ],
        'Field Sales' => ['inventory.product.view'],
        'Manager' => ['inventory.stock.view', 'inventory.report', 'inventory.product.view'],
    ],

    'doc_types' => [
        'PRD' => 'inventory::doc.product_code',
        'ADJ' => 'inventory::doc.adjustment',
        'STF' => 'inventory::doc.transfer',

        /* মাল গোনা — SC-2026-2027-0001 */
        'SC' => 'inventory::doc.stock_count',

        /* রান্না — হাঁড়ির উৎপাদন। */
        'CKG' => 'inventory::doc.production',

        /*
         * গুদামের কোডও সিরিজ থেকে — মালিকের নির্দেশ (২০২৬-০৮-০৭):
         * "সব জায়গায় কোড অটো বসবে"।
         *
         * হাতে লেখা কোডে দুইটা সমস্যা ছিল, দুইটাই মানুষের নয়, ব্যবস্থার:
         * একই কোড দুইবার বসানোর চেষ্টা (তখন একটা ভুল বার্তা, আর ফর্মটা
         * আবার ভরতে হত), আর প্রতিটা নতুন রেকর্ডে "কী কোড দেব" ভাবার
         * সময়টুকু। দুইটাই কাজের কোনো অংশ নয়।
         */
        'WHS' => 'inventory::doc.warehouse_code',
    ],

    'drill_sources' => [
        'product' => Product::class,
        'warehouse' => Warehouse::class,
        'stock_transfer' => StockTransfer::class,
    ],

    /*
     * একই নামে দুইবার পণ্য ঢুকতে না দেওয়া — যন্ত্র [[DuplicationEngine]],
     * ঘোষণা এখানে। নাম দুই ভাষাতেই মেলানো হয় (বাংলা-first এন্ট্রি সাধারণ);
     * পণ্যে ফোন নেই, তাই কেবল নাম — মিললে সতর্ক করে থামে, allow_duplicate
     * দিলে এগোয়। এই দরজাটাই এতদিন ছিল না, তাই লাইভে জোড়া পণ্য বসেছিল।
     */
    'duplicates' => [
        ['model' => Product::class, 'name' => ['name_en', 'name_bn']],
    ],

    /*
     * যে মডেলগুলো অডিটে যায় না — আর কেন।
     *
     * ── কেন তালিকাটা এখানে, কোরে নয় ────────────────────────────────
     * আগে এই তিনটা কোরের AuditEngine::NOT_AUDITED-এ লেখা ছিল, অর্থাৎ
     * কোর জানত মজুদ নামে একটা মডিউল আছে আর তার তিনটা মডেল কী কী।
     * সবাই কোরের উপর দাঁড়ায়; কোর কারও নাম জানলে তাকে ছাড়া কোর চলে না।
     *
     * পাহারাটা যায়নি: AuditCoverageTest দুইটা তালিকা মিলিয়ে দেখে, তাই
     * নতুন মডেল লিখে অডিট বসাতে ভুলে গেলে আগের মতোই টেস্ট ভাঙবে।
     *
     * তিনটাই append-only খাতা — সারি বদলায় না, আর প্রতিটা সারি কোনো
     * না কোনো অডিটেড ডকুমেন্ট থেকে এসেছে। অডিট বসালে একটা বিক্রয়ে
     * দুই-তিনটা বাড়তি সারি জমত, নতুন কোনো তথ্য ছাড়াই।
     */
    'audit_exempt' => [
        StockMovement::class => 'append-only stock ledger, traceable to its audited document',
        CostLayer::class => 'append-only cost ledger, traceable to the document that brought the goods in',
        CostLayerUse::class => 'each row is itself the record of one draw, from an audited document',
    ],

    // পণ্যে নিজস্ব ঘর — তাকের কোড, সরবরাহকারীর নিজস্ব নম্বর, আর যা
    // কোম্পানির দরকার। গুদাম বা স্থানান্তরে নয়: ওগুলোয় মানুষ নিজের
    // তথ্য লেখে না।
    'custom_fields' => ['product'],

    'imports' => [
        'product' => ProductImporter::class,

        /*
         * খোলা মজুদ — পণ্যের ইমপোর্টের পরে, আলাদা ফাইল।
         *
         * পণ্যের তালিকা অফিসে বসে তৈরি হয়, আর গোনা মজুদ গুদামে দাঁড়িয়ে।
         * একই ফাইলে চাইলে কোনোটাই শেষ হত না — আর পণ্য না বসিয়ে মজুদ
         * বসানোও যায় না, তাই ক্রমটাও এখানেই বলা।
         */
        'opening_stock' => OpeningStockImporter::class,
    ],

    /*
     * অনুমোদন লাগতে পারে এমন কাজ।
     *
     * ⓘ আপাতত একটাই — গুদাম বদল, আর ওটার মুহূর্ত "রওনা" (`dispatch`),
     * কারণ ওখানেই মাল সত্যিই নড়ে।
     *
     * ⚠️ **মজুদ সমন্বয় ও মাল ইস্যু এখানে নেই, আর সেটা ইচ্ছাকৃত।**
     * `StockAdjustmentService::adjust()`-এর কোনো খসড়া অবস্থা নেই — ডাকা
     * মাত্রই মাল নড়ে যায় আর খতিয়ানে বসে। অর্থাৎ অনুমোদন চাওয়ার মতো
     * কোনো কাগজ **তৈরি হওয়ার আগে থাকেই না**, আর পরে চাইলে সেটা পাহারা
     * নয় — মাল তো ততক্ষণে বেরিয়ে গেছে। ⓘ `reason_codes.needs_approval`
     * চিহ্নটা ঠিক এই জায়গার জন্যই বসানো, কিন্তু ওটা কাজে লাগাতে হলে
     * আগে একটা অপেক্ষমাণ অবস্থা লাগবে — সেটা নকশার সিদ্ধান্ত, তাই
     * মালিকের কাছে তোলা হয়েছে, নিজে থেকে বানানো হয়নি।
     */
    'approvals' => [
        'transfer' => 'inventory::approval.transfer',
    ],

    'reports' => [
        StockReports::class,
    ],

    /*
     * এই মডিউলের ড্যাশবোর্ড — কোরের [[DashboardEngine]] এটাই ডাকে।
     *
     * একটাই, তালিকা নয়: ড্যাশবোর্ড একটা পর্দা, আর দুইটা ঘোষণা করলে
     * কোনটা খুলবে তার কোনো ভালো উত্তর নেই।
     */
    'dashboard' => InventoryDashboard::class,

    'widgets' => [
        InventoryWidgets::class,
    ],

    'settings' => [
        [
            /*
             * সর্বনিম্ন মজুদের সতর্কতা।
             *
             * বন্ধ রাখা যায়, কারণ সব প্রতিষ্ঠান পুনঃক্রয়ের স্তর ধরে চলে
             * না — ছোট ডিপোতে মালিক নিজেই জানেন কী ফুরিয়ে আসছে। কিন্তু
             * যারা চালান, তাদের জন্য এটাই সবচেয়ে বেশি কাজে লাগে।
             */
            'key' => 'inventory.reorder_alert',
            'label' => 'inventory::settings.reorder_alert',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            // কত দিন না নড়লে "অচল" — সংখ্যাটা ব্যবসাভেদে আলাদা, তাই
            // কোডে লিখে রাখা যায় না
            'key' => 'inventory.non_moving_days',
            'label' => 'inventory::settings.non_moving_days',
            'type' => 'integer',
            'default' => 90,
            'group' => 'entry',
        ],
        [
            /*
             * ব্যাচ ও মেয়াদ ধরা হবে কি না।
             *
             * ডিফল্ট বন্ধ, কারণ ABOS-এর প্রথম ব্যবহারকারী একটা ডিপো,
             * ফার্মেসি নয়। চালু করলে পণ্যের ফর্মে "ব্যাচ ধরো" ঘরটা
             * দেখা যায়, মাল বুঝে নেওয়ার সময় লট ও মেয়াদ চাওয়া হয়, আর
             * মেয়াদের রিপোর্টটা মেনুতে আসে।
             *
             * সুইচটা কেবল **পর্দার** — নিয়মগুলো নয়। যে পণ্যে ব্যাচ ধরা
             * আছে, তার MRP সিলিং বা মেয়াদের বাধা এই সুইচ বন্ধ করলেও
             * খাটে; নাহলে সুইচ নামিয়ে মেয়াদোত্তীর্ণ মাল বেচা যেত।
             */
            'key' => 'inventory.batch_enabled',
            'label' => 'inventory::settings.batch_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            /*
             * প্যাকে লেখা যাবে কি না — বাক্স, পাতা, পিস।
             *
             * ডিফল্ট বন্ধ, কারণ বেশিরভাগ ডিপো এক এককেই বেচে, আর তাদের
             * প্রতিটা সারিতে একটা বাড়তি ড্রপডাউন কেবল টাইপিং বাড়াত।
             *
             * সুইচটা কেবল **পর্দার**। বন্ধ থাকলে unit_id পাঠানোর ঘরটাই
             * থাকে না, কিন্তু আগের যেসব লাইন প্যাকে লেখা হয়েছিল সেগুলো
             * কাগজে প্যাকেই ছাপা হয় — নাহলে সুইচ নামানোর দিন পুরনো
             * চালানগুলো হঠাৎ অন্য সংখ্যা দেখাত।
             */
            'key' => 'inventory.pack_entry_enabled',
            'label' => 'inventory::settings.pack_entry_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            // ব্র্যান্ডের ঘরটা সব ব্যবসায় লাগে না (নিয়ম ৭)
            'key' => 'inventory.brand_enabled',
            'label' => 'inventory::settings.brand_enabled',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
    ],

    /*
     * মজুদ একটা "দেখার সীমা" দিতে পারে — গুদাম ধরে (ভাগ চ)।
     *
     * ── কেন এই ঘোষণাটা এখানে, ব্যবহারকারীর পর্দায় নয় ───────────────
     * পর্দাটা SystemAdmin-এ, আর সে গুদামের নামগুলো দেখাতে চায়।
     * সরাসরি `Warehouse::class` আমদানি করলে system_admin চিরকাল
     * Inventory ছাড়া চলত না — `BoundariesTest` সাথে সাথেই ধরেছে।
     *
     * `depends_on`-এ লিখে দেওয়া যেত, কিন্তু সেটা মিথ্যা: ব্যবহারকারী
     * ব্যবস্থাপনা মজুদ ছাড়াই চলে। তাই উল্টো দিক — মজুদ নিজে বলে সে
     * কী দিতে পারে, আর পর্দাটা কেবল তালিকাটা পড়ে। মজুদ না থাকলে
     * গুদামের ঘরগুলোই বসে না, আর সেটাই সঠিক আচরণ।
     */
    'data_scopes' => [
        UserDataScope::WAREHOUSE => [
            'model' => Warehouse::class,
            'label' => 'inventory::field.warehouse',
        ],
    ],
    /*
     * যে ঘরগুলো সবাই দেখবে না।
     *
     * ── কী ভাঙা ছিল (২ সেপ্টেম্বর ২০২৬) ─────────────────────────────
     * পণ্যের পাতায় ক্রয়মূল্য **কোনো পাহারা ছাড়াই** দেখানো হত। যিনি
     * পণ্য দেখতে পান — অর্থাৎ কার্যত সবাই — তিনি প্রতিটা পণ্য কত দামে
     * কেনা হয়েছে তা দেখতে পেতেন।
     *
     * বিক্রির সময় প্রথম যে প্রশ্নটা আসে, এটা ঠিক সেটাই: *"আমার
     * সেলসম্যান কি ক্রয়মূল্য দেখতে পাবে?"* উত্তরটা ছিল "হ্যাঁ"।
     *
     * ── খোলা মজুদের দরও ─────────────────────────────────────────────
     * ওই তালিকার `unit_cost` ঘরটাও একই জিনিস — অন্য নামে ক্রয়মূল্য।
     * একটা ঢেকে অন্যটা খোলা রাখলে পাহারাটা অলংকার হয়ে যেত।
     */
    'sensitive_fields' => [
        Product::class => [
            'purchase_price' => 'inventory.cost.view',
        ],
        StockMovement::class => [
            'unit_cost' => 'inventory.cost.view',
        ],
    ],

];
