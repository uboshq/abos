<?php

declare(strict_types=1);

use App\Modules\Inventory\Imports\ProductImporter;
use App\Modules\Inventory\Models\Product;
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
            ['label' => 'inventory::menu.warehouses', 'route' => 'inventory.warehouse.index', 'permission' => 'inventory.warehouse.view'],
        ],
        'transactions' => [
            ['label' => 'inventory::menu.stock', 'route' => 'inventory.stock.index', 'permission' => 'inventory.stock.view'],
            ['label' => 'inventory::menu.adjust', 'route' => 'inventory.stock.adjust', 'permission' => 'inventory.stock.adjust'],
        ],
        'reports' => [
            ['label' => 'inventory::menu.stock_ledger', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'stock-ledger'], 'permission' => 'inventory.report'],
            ['label' => 'inventory::menu.stock_summary', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'stock-summary'], 'permission' => 'inventory.report'],
            ['label' => 'inventory::menu.hold_report', 'route' => 'inventory.report.show',
                'route_params' => ['slug' => 'hold'], 'permission' => 'inventory.report'],
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
        'inventory.report',
        'inventory.manage',
    ],

    'doc_types' => [
        'PRD' => 'inventory::doc.product_code',
        'ADJ' => 'inventory::doc.adjustment',
    ],

    'drill_sources' => [
        'product' => Product::class,
        'warehouse' => Warehouse::class,
    ],

    'imports' => [
        'product' => ProductImporter::class,
    ],

    'reports' => [
        StockReports::class,
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
            // ব্র্যান্ডের ঘরটা সব ব্যবসায় লাগে না (নিয়ম ৭)
            'key' => 'inventory.brand_enabled',
            'label' => 'inventory::settings.brand_enabled',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
    ],
];
