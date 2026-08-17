<?php

declare(strict_types=1);

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

    // একক, ভ্যাট ও কারণ-কোড মাস্টার ডাটা থেকে; মূল্যায়ন হিসাবের খাতায় যায়
    'depends_on' => ['master_data', 'accounts'],

    'menu' => [
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
        ],
        'transactions' => [
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
        ],
        'reports' => [
            ['label' => 'inventory::menu.stock_ledger', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'stock-ledger'], 'permission' => 'inventory.report'],
            ['label' => 'inventory::menu.stock_summary', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'stock-summary'], 'permission' => 'inventory.report'],
            ['label' => 'inventory::menu.hold_report', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'hold'], 'permission' => 'inventory.report'],

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

    'doc_types' => [
        'PRD' => 'inventory::doc.product_code',
        'ADJ' => 'inventory::doc.adjustment',
        'STF' => 'inventory::doc.transfer',

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

    'reports' => [
        StockReports::class,
    ],

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
];
