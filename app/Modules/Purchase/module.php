<?php

declare(strict_types=1);

use App\Modules\Purchase\Dashboard\PurchaseWidgets;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Reports\PurchaseReports;

/**
 * Purchase — প্ল্যান Phase 7।
 *
 * তিনটা ডকুমেন্ট, আর তিনটা আলাদা প্রশ্নের উত্তর:
 *
 *     ক্রয় আদেশ (PO)  — কী আনতে বলেছি          → খাতা নড়ে না, স্টকও নড়ে না
 *     মাল বুঝে নেওয়া (GRN) — কী সত্যিই এসেছে   → স্টক বাড়ে, দায় জন্মায়
 *     ক্রয় বিল          — কী দিতে হবে           → দায় সরবরাহকারীর নামে বসে
 *
 * ── কেন তিনটা, দুইটা নয় ────────────────────────────────────────────────
 * মাল আসা আর বিল আসা একই দিনে হয় না। ট্রাক আসে সোমবার, বিল আসে বৃহস্পতিবার।
 * ওই তিন দিন মালটা গুদামে আছে — বেচা যাচ্ছে, তার দাম আছে — অথচ কাগজে কোনো
 * দায় নেই। শুধু বিলের সময় হিসাব বসালে ওই তিন দিন ব্যালেন্স শিট মিথ্যা বলত:
 * সম্পদ বেড়েছে, দায় বাড়েনি, আর পার্থক্যটা নীরবে মুনাফা হয়ে দেখাত।
 *
 * তাই মাল বুঝে নেওয়ার দিনই হিসাব বসে, একটা অপেক্ষমাণ দায়ে:
 *
 *     মাল বুঝে নেওয়া:  Dr মজুদ পণ্য (1120)      Cr প্রাপ্ত মাল, বিল আসেনি (2160)
 *     বিল এল:          Dr প্রাপ্ত মাল, বিল আসেনি  Cr প্রদেয় হিসাব (2110, সরবরাহকারী)
 *
 * ২১৬০ খাতটা তাই একটা প্রশ্নের উত্তর দেয় যেটা আর কোথাও পাওয়া যায় না:
 * "কত টাকার মাল ঢুকেছে যার বিল এখনো আসেনি"। খাতটা শূন্য না হলে হয় বিল
 * বাকি, নয় কেউ বিল ছাড়াই মাল নামিয়েছে — দুটোই জানা দরকার।
 *
 * ── স্টক ও হিসাব একই ট্রানজেকশনে ───────────────────────────────────────
 * ইভেন্টে নয় (প্ল্যান WP-0.3)। ইভেন্টে করলে একটা বসে অন্যটা ব্যর্থ হতে
 * পারত, আর তখন গুদামে মাল থাকত কিন্তু খাতায় থাকত না — কোনো ভুল বার্তা
 * ছাড়াই।
 */
return [
    'code' => 'purchase',

    'name' => [
        'en' => 'Purchase',
        'bn' => 'ক্রয়',
    ],

    'version' => '1.0.0',

    // মাল স্টকে বসে, দায় খাতায় বসে, আর সরবরাহকারী ছাড়া ক্রয় হয় না
    'depends_on' => ['master_data', 'accounts', 'inventory', 'supplier'],

    'menu' => [
        'transactions' => [
            ['label' => 'purchase::menu.orders', 'route' => 'purchase.order.index', 'permission' => 'purchase.order.view',
                'setting' => 'purchase.screen_orders'],
            ['label' => 'purchase::menu.receipts', 'route' => 'purchase.receipt.index', 'permission' => 'purchase.receipt.view',
                'setting' => 'purchase.screen_receipts'],
            ['label' => 'purchase::menu.bills', 'route' => 'purchase.bill.index', 'permission' => 'purchase.bill.view'],
            ['label' => 'purchase::menu.payments', 'route' => 'purchase.payment.index', 'permission' => 'purchase.payment.view'],
            ['label' => 'purchase::menu.returns', 'route' => 'purchase.return.index', 'permission' => 'purchase.return.view'],
        ],
        'reports' => [
            ['label' => 'purchase::menu.pending_orders', 'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'pending-orders'], 'permission' => 'purchase.report'],
            ['label' => 'purchase::menu.uninvoiced', 'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'uninvoiced'], 'permission' => 'purchase.report'],
            ['label' => 'purchase::menu.by_supplier', 'route' => 'purchase.report.show',
                'route_params' => ['slug' => 'by-supplier'], 'permission' => 'purchase.report'],
        ],
    ],

    'permissions' => [
        'purchase.order.view',
        'purchase.order.create',
        'purchase.order.update',
        'purchase.order.cancel',
        'purchase.receipt.view',
        'purchase.receipt.create',
        'purchase.receipt.cancel',
        'purchase.bill.view',
        'purchase.bill.create',
        'purchase.bill.cancel',

        /*
         * পরিশোধের চাবি আলাদা তিনটা।
         *
         * যিনি বিল তোলেন আর যিনি টাকা দেন — বেশিরভাগ ডিপোতে দুইজন
         * আলাদা মানুষ, আর ইচ্ছাকৃতভাবে: একজনেই দুইটা করলে ভুয়া বিল
         * তুলে নিজেই তার টাকা দিয়ে দেওয়া যেত, আর কাগজে সবই মিলত।
         */
        'purchase.payment.view',
        'purchase.payment.create',
        'purchase.payment.cancel',

        /*
         * ফেরতের চাবিও আলাদা।
         *
         * ফেরত মানে প্রদেয় কমিয়ে দেওয়া — টাকা না দিয়েই দায় থেকে অঙ্ক
         * সরানো। যে মাল বুঝে নেয় তার হাতে এই ক্ষমতা থাকলে ভুয়া ফেরত
         * দেখিয়ে ঘাটতি ঢাকা যেত।
         */
        'purchase.return.view',
        'purchase.return.create',
        'purchase.return.cancel',

        'purchase.report',
        'purchase.manage',
    ],

    'doc_types' => [
        'PO' => 'purchase::doc.order',
        'GRN' => 'purchase::doc.receipt',
        'PBL' => 'purchase::doc.bill',
        /*
         * কোডগুলো ছোট, উপসর্গগুলো নয়।
         *
         * কাগজে ছাপা হয় উপসর্গ (PMT-2026-2027-0001), আর কোডটা ভেতরের
         * নাম। SP-কে PAY বলা যেত না: হিসাবের পরিশোধ ভাউচার আগে থেকেই
         * PAY উপসর্গ নেয়, আর দুইটা কাগজে একই নম্বর ছাপা হলে মেলানোর
         * সময় কেউ বুঝত না কোনটার কথা।
         */
        'SP' => 'purchase::doc.payment',
        'PR' => 'purchase::doc.return',
    ],

    'drill_sources' => [
        'purchase_order' => PurchaseOrder::class,
        'purchase_receipt' => PurchaseReceipt::class,
        'purchase_bill' => PurchaseBill::class,
        'purchase_payment' => Payment::class,
        'purchase_return' => PurchaseReturn::class,
    ],

    'reports' => [
        PurchaseReports::class,
    ],

    'widgets' => [
        PurchaseWidgets::class,
    ],

    'settings' => [
        /*
         * কোন পর্দাগুলো থাকবে — বিক্রয়ের মতোই (Sales/module.php)।
         *
         * ছোট ডিপো সরাসরি কেনে: গাড়ি আসে, মাল নামে, চালান হাতে ধরিয়ে
         * দেয়। তার মেনুতে "ক্রয় আদেশ" আর "মাল গ্রহণ" দুইটা সারি সারা
         * বছর অব্যবহৃত পড়ে থাকে। বড় প্রতিষ্ঠানে ঠিক উল্টো — ওখানে
         * আদেশ ছাড়া মাল ঢোকে না।
         */
        [
            'key' => 'purchase.screen_direct',
            'label' => 'purchase::settings.screen_direct',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
        ],
        [
            'key' => 'purchase.screen_orders',
            'label' => 'purchase::settings.screen_orders',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',

            // কাগজ থাকলে আড়াল করা যাবে না — কারণটা Sales/module.php-এ
            'holds' => PurchaseOrder::class,
        ],
        [
            'key' => 'purchase.screen_receipts',
            'label' => 'purchase::settings.screen_receipts',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
            'holds' => PurchaseReceipt::class,
        ],
        [
            /*
             * বিল ছাড়া মাল নেওয়া যাবে কি না।
             *
             * বড় প্রতিষ্ঠানে আদেশ ছাড়া মাল ঢোকে না — নিয়ন্ত্রণটাই মূল কথা।
             * ছোট ডিপোতে উল্টো: সরবরাহকারী মাল নামিয়ে দিয়ে যান, কাগজ পরে
             * হয়। দুটোই বাস্তব, তাই কোডে একটা বেছে নেওয়া যায় না (নিয়ম ৭)।
             */
            'key' => 'purchase.receipt_needs_order',
            'label' => 'purchase::settings.receipt_needs_order',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            /*
             * আদেশের চেয়ে বেশি মাল নেওয়া যাবে কতটুকু বেশি।
             *
             * শূন্য মানে এক কেজিও বেশি নয়। বাস্তবে বস্তায় ভরা মালে দুই-এক
             * শতাংশ এদিক-ওদিক হয়, আর প্রতিবার আদেশ সংশোধন করতে বললে
             * গুদামের লোক শেষে আদেশ ছাড়াই মাল নামাতে শুরু করেন।
             */
            'key' => 'purchase.over_receipt_percent',
            'label' => 'purchase::settings.over_receipt_percent',
            'type' => 'integer',
            'default' => 0,
            'group' => 'entry',
        ],
        [
            // বিল আদেশের দামের সাথে না মিললে আটকে দেওয়া হবে কি না
            'key' => 'purchase.block_price_mismatch',
            'label' => 'purchase::settings.block_price_mismatch',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
    ],
];
