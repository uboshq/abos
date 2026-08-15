<?php

declare(strict_types=1);

use App\Modules\Sales\Dashboard\SalesWidgets;
use App\Modules\Sales\Events\InvoiceConfirmed;
use App\Modules\Sales\Integrity\SalesChecks;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Panels\SalesFacts;
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
            ['label' => 'sales::menu.pos', 'route' => 'sales.pos.index', 'permission' => 'sales.pos',
                'setting' => 'sales.screen_pos'],

            /*
             * শিফট — কাউন্টারের ঠিক নিচে, একই সুইচের পেছনে।
             *
             * যে ব্যবসায় কাউন্টারের পর্দাই নেই, তার ড্রয়ারের শিফটও নেই।
             */
            ['label' => 'sales::menu.shift', 'route' => 'sales.shift.index', 'permission' => 'sales.pos',
                'setting' => 'sales.screen_pos'],
            ['label' => 'sales::menu.direct', 'route' => 'sales.direct.create', 'permission' => 'sales.challan.create',
                'setting' => 'sales.screen_direct'],
            ['label' => 'sales::menu.orders', 'route' => 'sales.order.index', 'permission' => 'sales.order.view',
                'setting' => 'sales.screen_orders'],
            ['label' => 'sales::menu.challans', 'route' => 'sales.challan.index', 'permission' => 'sales.challan.view',
                'setting' => 'sales.screen_challans'],
            ['label' => 'sales::menu.invoices', 'route' => 'sales.invoice.index', 'permission' => 'sales.invoice.view'],
            ['label' => 'sales::menu.collections', 'route' => 'sales.collection.index', 'permission' => 'sales.collection.view'],
            ['label' => 'sales::menu.returns', 'route' => 'sales.return.index', 'permission' => 'sales.return.view'],

            /*
             * যে কাগজ বেরোয়নি।
             *
             * ── কেন কাউন্টারের সুইচের পেছনে নয় ──────────────────────
             * প্রিন্টার আটকায় কাউন্টারেও, অফিসের ডেস্কেও — বিল ও চালান
             * দুই জায়গা থেকেই ছাপা হয়। `sales.screen_pos`-এর পেছনে
             * রাখলে যে ডিপো কাউন্টার ব্যবহার করে না তাদের আটকে যাওয়া
             * কাগজগুলো কোথাও দেখা যেত না।
             *
             * সাধারণত সারিটা খালি, আর খালি থাকাই স্বাভাবিক — এটা
             * রোজকার কাজের পর্দা নয়, প্রিন্টার বিগড়ানোর দিনের।
             */
            ['label' => 'sales::menu.print_queue', 'route' => 'sales.print_queue.index',
                'permission' => 'sales.invoice.view'],
        ],
        'reports' => [
            ['label' => 'sales::menu.pending_orders', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'pending-orders'], 'permission' => 'sales.report'],
            ['label' => 'sales::menu.undelivered', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'uninvoiced'], 'permission' => 'sales.report'],
            ['label' => 'sales::menu.by_customer', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'by-customer'], 'permission' => 'sales.report'],

            /*
             * কোন পণ্যে কত লাভ।
             *
             * ── কেন `sales.cost.view`-এর পেছনে নয় ───────────────────
             * সারিটা `sales.report`-এই থাকে, কারণ রিপোর্টটা বিক্রয়কর্মীরও
             * কাজে লাগে — কোন পণ্য কত বিকোচ্ছে, কোথায় ছাড় বেশি যাচ্ছে।
             * ক্রয়মূল্য, মুনাফা ও মার্জিনের কলাম তিনটা আলাদা করে ঢাকা
             * (নিয়ম ২৪)। মেনু ধরে আটকালে হয় তাঁর কাজ বন্ধ, নয় সব খোলা।
             */
            ['label' => 'sales::menu.by_product', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'by-product'], 'permission' => 'sales.report'],

            /*
             * ব্র্যান্ড ধরে — দুইশো পণ্যের তালিকায় যা চোখে পড়ে না,
             * বিশটা ব্র্যান্ডে পড়ে। আর দরকষাকষিটাও হয় ব্র্যান্ড ধরে।
             */
            ['label' => 'sales::menu.by_brand', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'by-brand'], 'permission' => 'sales.report'],

            /*
             * রিকল — এই লটটা কাদের কাছে গেছে।
             *
             * ── কেন সুইচের পেছনে নয় ─────────────────────────────────
             * `inventory.batch_enabled`-এর পেছনে রাখতে চেয়েছিলাম, আর
             * মডিউল রেজিস্ট্রি সেটা ফিরিয়ে দিয়েছে — ঠিকই করেছে: একটা
             * মডিউল অন্যের সেটিং দিয়ে নিজের মেনু আটকালে সুইচটা বন্ধ
             * থাকলে সারিটা চিরকাল অদৃশ্য থাকত, আর কোথাও লেখা থাকত না
             * কেন।
             *
             * নিজের একটা দ্বিতীয় সুইচ বানানোও চলে না — তখন একই জিনিস
             * চালু করতে দুই জায়গায় দুইবার টিক দিতে হত।
             *
             * তাই সারিটা সবসময় থাকে। লট না থাকলে পর্দার তালিকা খালি,
             * আর পর্দাই বলে দেয় কিছু নেই।
             */
            ['label' => 'sales::menu.lot_trace', 'route' => 'sales.lot.trace',
                'permission' => 'sales.challan.view'],
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

        /*
         * ফেরতের চাবি বিক্রয়ের চাবি থেকে আলাদা।
         *
         * ফেরত নেওয়া মানে গ্রাহকের পাওনা কমিয়ে দেওয়া — টাকা ছাড়াই
         * খাতা থেকে অঙ্ক সরানো। বিক্রি করার অধিকার থাকলেই সেটা করা
         * যাবে না; নাহলে যে কেউ ভুয়া ফেরত দেখিয়ে নিজের ঘাটতি ঢাকতে
         * পারত।
         */
        'sales.return.view',
        'sales.return.create',
        'sales.return.cancel',
        'sales.pos',
        'sales.discount.override',
        'sales.report',

        /*
         * ক্রয়মূল্য ও মুনাফা দেখার অনুমতি — রিপোর্ট দেখার থেকে আলাদা।
         *
         * ── কেন আলাদা ───────────────────────────────────────────────
         * বিক্রয়কর্মীর "কে কত কিনছে" জানা দরকার, "কত লাভ হলো" নয়।
         * একই অনুমতিতে রাখলে হয় তাঁর রিপোর্ট বন্ধ, নয় ক্রয়মূল্য ফাঁস —
         * আর ক্রয়মূল্য জানা থাকলে দরকষাকষিতে সেটাই ব্যবহার হয়।
         *
         * নামটা `sales.cost.view`, কারণ যেখানে যেখানে ঢাকা পড়বে
         * সবগুলোই বিক্রয়ের পর্দা: ক্রেতা ধরে মুনাফা, আর বিলের গায়ে
         * বিক্রীত পণ্যের ব্যয়।
         */
        'sales.cost.view',

        /*
         * সীমার বাইরে ছাপার অনুমতি।
         *
         * প্রিন্টার কাগজ চিবোয়, কালি ফুরায়, ক্রেতা কপি হারান — কেউ
         * ছাড়াতে না পারলে ওই কাগজটা আর কোনোদিন ছাপা যেত না, আর কেউ
         * বিলটা বাতিল করে নতুন বিল কাটতেন। সেটা অনেক বেশি ক্ষতিকর।
         */
        'sales.reprint.override',

        'sales.manage',
    ],

    'doc_types' => [
        'SO' => 'sales::doc.order',
        'DC' => 'sales::doc.challan',
        'INV' => 'sales::doc.invoice',
        'COL' => 'sales::doc.collection',
        'SR' => 'sales::doc.return',
    ],

    'drill_sources' => [
        'sales_order' => SalesOrder::class,
        'delivery_challan' => DeliveryChallan::class,
        'sales_invoice' => SalesInvoice::class,
        'collection' => Collection::class,
        'sales_return' => SalesReturn::class,
    ],

    'reports' => [
        SalesReports::class,
    ],

    /*
     * এই মডিউল যে ঘটনাগুলো ঘোষণা করে — একটা চুক্তি।
     *
     * অন্য মডিউল এই তালিকা দেখে ঠিক করে কার কথা শুনবে। তালিকায় না
     * থাকা মানে ওটা এই মডিউলের ভেতরের ব্যাপার, কাল বদলে যেতে পারে।
     *
     * খেয়াল রাখতে হবে: বিলের **দাখিলা ও স্টক চলাচল ইভেন্টে যায় না** —
     * ওগুলো confirm()-এর ভেতরে, একই ট্রানজেকশনে। ইভেন্ট একদিন হারায়,
     * খাতা হারানো যায় না।
     */
    'events' => [
        InvoiceConfirmed::class,
    ],

    /*
     * গ্রাহকের পাতায় বিক্রয়ের বক্তব্য — "শেষ কেনা কবে"।
     *
     * আগে উত্তরটা দিত Customer নিজে, `SalesInvoice` খুঁজে। তাতে
     * customer → sales → customer চক্র তৈরি হত, আর বিক্রয় ছাড়া
     * গ্রাহকের পাতাটাই খুলত না। এদিক থেকে দিলে কোনো নতুন নির্ভরতা
     * লাগে না — Sales গ্রাহককে আগে থেকেই চেনে।
     */
    'facts' => [
        SalesFacts::class,
    ],

    // হোম পর্দার সংখ্যাগুলো — কোর জিজ্ঞেস করে, মডিউল উত্তর দেয়
    'widgets' => [
        SalesWidgets::class,
    ],

    /*
     * সংখ্যাগুলোর সংজ্ঞা — কে কী গোনে, তার একমাত্র তালিকা।
     *
     * "আজকের বিক্রয়" এই মডিউলেই তিন জায়গায় লাগে: হোম পর্দা, কাউন্টার,
     * রিপোর্ট। প্রত্যেকে নিজে গুনলে একদিন তিনটা আলাদা হয় — আর ঠিক তাই
     * হয়েছিল, কাউন্টারের ঘরটা খসড়াও গুনত। এখন সংজ্ঞা এক জায়গায়, আর
     * সংখ্যার পাশে সেটা দেখাও যায়।
     */
    'metrics' => [
        SalesMetrics::class,
    ],

    /*
     * বিক্রয়ের কাগজ নিজের সাথে মেলে কি না।
     *
     * মোটটা জমানো থাকে, প্রতিবার নতুন করে গোনা হয় না — নাহলে প্রতিটা
     * তালিকার পাতায় প্রতিটা বিলের সব লাইন টানতে হত। কিন্তু জমানো
     * মানেই বাসি হওয়ার সুযোগ।
     */
    'integrity' => [
        SalesChecks::class,
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
         * কোন পর্দাগুলো থাকবে — মালিকের সিদ্ধান্ত, কোডের নয়।
         *
         * প্রতিষ্ঠানভেদে কাজের ধরন আলাদা: ডিপো সরাসরি বেচে, দোকানে
         * কাউন্টার লাগে, কেউ অর্ডার নিয়ে পরে পাঠায়। যেটা লাগে না সেই
         * সারিটা মেনুতে থাকলে প্রতিদিন সেটা এড়িয়ে যেতে হয়, আর একদিন
         * তাড়াহুড়োয় ওখানেই ঢুকে পড়ে।
         *
         * সুইচ বন্ধ মানে শুধু মেনু থেকে উধাও — কোড, রুট, কাগজ কিছুই
         * যায় না। তাই যেকোনো দিন ফেরানো যায়, আর পুরনো কাগজগুলোও
         * তাদের নিজের ঠিকানায় খোলা থাকে।
         */
        [
            'key' => 'sales.screen_pos',
            'label' => 'sales::settings.screen_pos',
            'type' => 'boolean',

            /*
             * ডিফল্ট বন্ধ — একমাত্র এই সুইচটাই।
             *
             * কাউন্টার POS দোকানের জিনিস, পরিবেশকের নয়, আর ABOS-এর
             * প্রথম ব্যবহারকারী একটা ডিপো। বাকি পর্দাগুলো ডিফল্ট চালু:
             * যা আছে তা হঠাৎ উধাও হয়ে গেলে সেটা আপগ্রেডে ভাঙা মনে হয়।
             */
            'default' => false,
            'group' => 'screens',
        ],
        [
            'key' => 'sales.screen_direct',
            'label' => 'sales::settings.screen_direct',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
        ],
        [
            'key' => 'sales.screen_orders',
            'label' => 'sales::settings.screen_orders',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',

            /*
             * কাগজ থাকলে পর্দা আড়াল করা যাবে না।
             *
             * দশটা অর্ডার নিয়ে বসে থাকা কোম্পানির অর্ডার-পর্দা কেউ বন্ধ
             * করে দিলে ওই দশটা কাগজের কোনো দরজা থাকত না — অথচ সেগুলো
             * বাতিলও হয়নি, শেষও হয়নি। কোরে মডিউলের নাম নেই: ক্লাসটা
             * মডিউল নিজে বলে, কোর শুধু গুনে দেখে (১৯.৭)।
             */
            'holds' => SalesOrder::class,
        ],
        [
            'key' => 'sales.screen_challans',
            'label' => 'sales::settings.screen_challans',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
            'holds' => DeliveryChallan::class,
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
            /*
             * একটা কাগজ সর্বোচ্চ কতবার ছাপা যাবে।
             *
             * ── কেন শূন্য মানে অসীম, আর সেটাই ডিফল্ট ─────────────────
             * চালু ব্যবস্থায় হঠাৎ সীমা বসালে যিনি রোজ তিনটা কপি ছাপেন
             * তাঁর কাজ কাল সকালে থামত, আর তিনি ভাবতেন আপগ্রেডে কিছু
             * ভেঙেছে। সংখ্যাটা মালিকের সিদ্ধান্ত — এক ডিপোর "যথেষ্ট"
             * আরেকটার "কম"।
             *
             * গোনা ও DUPLICATE ছাপ আগেই ছিল, কিন্তু দুইটাই নিষ্ক্রিয়:
             * তারা বলত কাগজটা দ্বিতীয়বার ছাপা, কেউ আটকাত না।
             */
            'key' => 'sales.reprint_limit',
            'label' => 'sales::settings.reprint_limit',
            'type' => 'integer',
            'default' => 0,
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
