<?php

declare(strict_types=1);

use App\Modules\Sales\Dashboard\SalesWidgets;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Reports\SalesReports;

/**
 * Sales — প্ল্যান Phase 8।
 *
 * চারটা ডকুমেন্ট, আর চারটা আলাদা মুহূর্ত:
 *
 *     বিক্রয় আদেশ   — গ্রাহক চেয়েছেন      → মাল অর্ডারে ধরা পড়ে (Reserved)
 *     ডেলিভারি চালান — মাল বেরিয়ে গেছে      → স্টক তাক থেকে নামে
 *     বিক্রয় বিল     — টাকা পাওনা হলো       → আয় ও প্রাপ্য খাতায় বসে
 *     আদায়          — টাকা এসেছে           → প্রাপ্য কমে
 *
 * ── কেন Reserved অবস্থাটা এখানেই লেখা হয় ─────────────────────────────
 * Inventory চারটা অবস্থা ঘোষণা করেছিল, কিন্তু Reserved-এ কেউ কিছু লিখত না —
 * কারণ অর্ডার নেওয়ার জায়গাটাই ছিল না। এখন আছে।
 *
 * অর্ডার নিশ্চিত হলে মালটা তাকেই থাকে, শুধু আর বেচা যায় না। সরিয়ে ফেললে
 * গুদামে দাঁড়িয়ে গোনা মানুষ বেশি পেতেন আর খাতা কম বলত, অথচ কিছুই যায়নি।
 * আর একেবারে না ধরলে একই শেষ কার্টনটা দুইজনকে বেচা হয়ে যেত — দুইটা চালান
 * ছাপা হত, আর ভুলটা ধরা পড়ত ক্রেতার সামনে।
 *
 * ── কেন চালান আর বিল আলাদা ───────────────────────────────────────────
 * ডিপোর গাড়ি সকালে মাল নিয়ে বেরোয়, বিল কাটা হয় ফেরার পর — অথবা মাস শেষে,
 * একসাথে। মাল বেরোনো আর টাকা পাওনা হওয়া একই মুহূর্ত নয়, তাই একই ডকুমেন্টে
 * বাঁধা যায় না। বাঁধলে হয় মাল আটকে থাকত বিলের অপেক্ষায়, নয় বিল কাটতে হত
 * এমন মালের যা এখনো ফেরত আসতে পারে।
 */
return [
    'code' => 'sales',

    'name' => [
        'en' => 'Sales',
        'bn' => 'বিক্রয়',
    ],

    'version' => '1.0.0',

    'depends_on' => ['master_data', 'accounts', 'inventory', 'customer'],

    'menu' => [
        'transactions' => [
            ['label' => 'sales::menu.pos', 'route' => 'sales.pos.index', 'permission' => 'sales.pos'],
            ['label' => 'sales::menu.direct', 'route' => 'sales.direct.create', 'permission' => 'sales.challan.create'],
            ['label' => 'sales::menu.orders', 'route' => 'sales.order.index', 'permission' => 'sales.order.view'],
            ['label' => 'sales::menu.challans', 'route' => 'sales.challan.index', 'permission' => 'sales.challan.view'],
            ['label' => 'sales::menu.invoices', 'route' => 'sales.invoice.index', 'permission' => 'sales.invoice.view'],
            ['label' => 'sales::menu.collections', 'route' => 'sales.collection.index', 'permission' => 'sales.collection.view'],
        ],
        'reports' => [
            ['label' => 'sales::menu.pending_orders', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'pending-orders'], 'permission' => 'sales.report'],
            ['label' => 'sales::menu.undelivered', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'uninvoiced'], 'permission' => 'sales.report'],
            ['label' => 'sales::menu.by_customer', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'by-customer'], 'permission' => 'sales.report'],
        ],
    ],

    'permissions' => [
        'sales.order.view',
        'sales.order.create',
        'sales.order.update',
        'sales.order.cancel',
        'sales.challan.view',
        'sales.challan.create',
        'sales.challan.cancel',
        'sales.invoice.view',
        'sales.invoice.create',
        'sales.invoice.cancel',
        'sales.collection.view',
        'sales.collection.create',
        'sales.collection.cancel',
        'sales.pos',
        'sales.discount.override',
        'sales.report',
        'sales.manage',
    ],

    'doc_types' => [
        'SO' => 'sales::doc.order',
        'DC' => 'sales::doc.challan',
        'INV' => 'sales::doc.invoice',
        'COL' => 'sales::doc.collection',
    ],

    'drill_sources' => [
        'sales_order' => SalesOrder::class,
        'delivery_challan' => DeliveryChallan::class,
        'sales_invoice' => SalesInvoice::class,
        'collection' => Collection::class,
    ],

    'reports' => [
        SalesReports::class,
    ],

    // হোম পর্দার সংখ্যাগুলো — কোর জিজ্ঞেস করে, মডিউল উত্তর দেয়
    'widgets' => [
        SalesWidgets::class,
    ],

    /*
     * যে কাজে এই মডিউল অনুমোদন চাইতে পারে।
     *
     * ছাড়ই একমাত্র, আর সেটাই সবচেয়ে দামি: বিলে বসানো প্রতিটা টাকার
     * ছাড় সরাসরি মুনাফা থেকে যায়, আর কাউন্টারে দাঁড়িয়ে সেটা দেওয়া
     * সবচেয়ে সহজ। কত টাকার উপরে অনুমোদন লাগবে সেটা কোম্পানি নিজে
     * ঠিক করে (অনুমোদনের ছক), কারণ এক ডিপোর "বড় ছাড়" আরেকটার
     * রোজকার ছাড়।
     */
    'approvals' => [
        'discount' => 'sales::approval.discount',
    ],

    'settings' => [
        [
            /*
             * অর্ডার নিশ্চিত হলে মাল ধরে রাখা হবে কি না।
             *
             * বেশিরভাগ ডিপোতে হ্যাঁ — নাহলে একই শেষ কার্টনটা দুইজনকে বেচা
             * হয়ে যায়। কিন্তু যে দোকানে অর্ডার আর ডেলিভারি একই মুহূর্তে,
             * সেখানে ধরে রাখাটা শুধু একটা বাড়তি ধাপ।
             */
            'key' => 'sales.reserve_on_order',
            'label' => 'sales::settings.reserve_on_order',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            /*
             * বিক্রয়যোগ্য মালের বেশি বেচা যাবে কি না।
             *
             * বন্ধ রাখাই ডিফল্ট। কিন্তু কিছু ডিপোতে মাল রাস্তায় আছে জেনেই
             * অর্ডার নেওয়া হয়, আর তখন আটকে দিলে অর্ডারটাই হাতছাড়া হয়।
             */
            'key' => 'sales.allow_negative_stock',
            'label' => 'sales::settings.allow_negative_stock',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            /*
             * কাউন্টারে যে গ্রাহকের নামে নগদ বিক্রি বসবে।
             *
             * POS-এ প্রতিবার গ্রাহক বাছতে বললে গতিটাই চলে যায় — কাউন্টারে
             * লাইন দাঁড়িয়ে থাকে। তাই একটা "নগদ গ্রাহক" আগে থেকে বসানো
             * থাকে, আর যিনি নাম-ঠিকানা দিতে চান কেবল তার বেলায় বাছতে হয়।
             *
             * আলাদা POS-গ্রাহক তালিকা নয়, একই মাস্টারের একটা সারি — দুইটা
             * তালিকা রাখলে একই দোকানের হিসাব দুই জায়গায় ভাগ হয়ে যেত।
             */
            'key' => 'sales.walkin_customer_id',
            'label' => 'sales::settings.walkin_customer',
            'type' => 'integer',
            'default' => 0,
            'group' => 'entry',
        ],
        /*
         * সরাসরি বিক্রয়ের ঘরগুলো — প্রতিটার নিজের সুইচ (নিয়ম ৭)।
         *
         * DMS-এ ঠিক এভাবেই, আর কারণটা বাস্তব: যে ডিপো ফ্রি মাল দেয় না
         * তার পর্দায় ফ্রি পরিমাণের ঘর থাকলে প্রতিবার সেটা এড়িয়ে যেতে হয়,
         * আর একদিন তাড়াহুড়োয় ওখানেই সংখ্যা বসে যায়।
         */
        [
            'key' => 'sales.field_free_qty',
            'label' => 'sales::settings.field_free_qty',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_gift',
            'label' => 'sales::settings.field_gift',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_line_discount',
            'label' => 'sales::settings.field_line_discount',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_expense',
            'label' => 'sales::settings.field_expense',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_rounding',
            'label' => 'sales::settings.field_rounding',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_do_no',
            'label' => 'sales::settings.field_do_no',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_deposit',
            'label' => 'sales::settings.field_deposit',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_credit_limit',
            'label' => 'sales::settings.field_credit_limit',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_warehouse_select',
            'label' => 'sales::settings.field_warehouse_select',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_sub_total',
            'label' => 'sales::settings.field_sub_total',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_total_item',
            'label' => 'sales::settings.field_total_item',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_sales_qty',
            'label' => 'sales::settings.field_sales_qty',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_free_qty_total',
            'label' => 'sales::settings.field_free_qty_total',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_total_qty',
            'label' => 'sales::settings.field_total_qty',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            // চালান ছাড়া সরাসরি বিল কাটা যাবে কি না — কাউন্টার বিক্রিতে লাগে
            'key' => 'sales.invoice_needs_challan',
            'label' => 'sales::settings.invoice_needs_challan',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
    ],
];
