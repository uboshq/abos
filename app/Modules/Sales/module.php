<?php

declare(strict_types=1);

use App\Core\Contracts\CreditHolds;
use App\Core\Engines\Print\PaperSize;
use App\Modules\Sales\Auth\CustomerProvider;
use App\Modules\Sales\Dashboard\SalesActivity;
use App\Modules\Sales\Dashboard\SalesDashboard;
use App\Modules\Sales\Dashboard\SalesWidgets;
use App\Modules\Sales\Events\InvoiceConfirmed;
use App\Modules\Sales\Integrity\SalesChecks;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Services\CreditExposure;
use App\Modules\Sales\Services\SalesDefaults;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\CommissionClaim;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\Shipment;
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

    /*
     * সাইডবারে কোথায় — নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     *
     * শৃঙ্খলের শেষ ধাপ।
     *
     * দলগুলোর তালিকা আর কেন এটা `depends_on`-এর থেকে আলাদা:
     * [[ModuleDefinition::NAV_SECTIONS]].
     */
    'nav' => ['section' => 'business', 'order' => 50],

    /*
     * `supplier`-টা যোগ হয়েছে কমিশনের দাবির জন্য।
     *
     * ডিপো আগে ডিলারকে কমিশন দেয়, পরে মিলের কাছে দাবি করে —
     * তাই প্রতিটা দাবির গায়ে কোন মিলের কাছে দাবি, সেটা লেখা থাকেই
     * (`supplier_id`)। এটা লুকানো ছিল না, ঘোষণা করা ছিল না — ধরেছে
     * `BoundariesTest`।
     *
     * এতদিন ঘোষণা করা যেতও না: Supplier-এর দুইটা রিপোর্ট বিক্রয়ের
     * নাম জানত, অর্থাৎ সিন্ধুকটা চক্র হয়ে যেত। ওই দুইটা এখন
     * Purchase-এ, তাই এদিকটা পরিষ্কার।
     */
    'depends_on' => ['master_data', 'accounts', 'inventory', 'customer', 'supplier'],

    /*
     * ⭐ বাকির সীমার আটকে থাকা টাকা — গ্রাহকের পাতার জন্য, ২৬ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ গ্রাহকের পাতা বিক্রয়কে চেনে না, অথচ তার "অবশিষ্ট সীমা" কাউন্টারের
     * সংখ্যার সাথে হুবহু মিলতে হয়। ⚠️ না বাঁধলে সে কোরের শূন্য পেত, আর
     * দুই পর্দায় দুই সংখ্যা দেখাত — কোথাও কিছু লাল হত না।
     */
    'bindings' => [
        CreditHolds::class => CreditExposure::class,
    ],

    'menu' => [
        'dashboard' => [
            ['label' => 'sales::dashboard.title', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'sales'], 'permission' => 'sales.invoice.view'],
        ],

        'transactions' => [
            /*
             * ⭐ ক্রমটা কাগজের নিজের ধারা ধরে — মালিকের নির্দেশ, ২১ সেপ্টেম্বর ২০২৬।
             *
             * ── ⓘ কেন এই ক্রম ─────────────────────────────────────────
             * বিক্রয়ে কাগজগুলো একটার পর একটা জন্মায়: আদেশ আসে → মাল
             * যায় (চালান) → গাড়ি ছাড়ে (শিপমেন্ট) → বিল হয় → ফেরত এলে
             * ফেরত। মেনুটা সেই ধারাতেই সাজানো, যাতে নতুন কর্মীও উপর
             * থেকে নিচে পড়ে কাজের ক্রমটা বুঝে নিতে পারেন।
             *
             * ⚠️ এটা ১৯ সেপ্টেম্বরের সিদ্ধান্তের উল্টো — তখন মালিক
             * বলেছিলেন *"Dashboard er por 'Invoice list'"*, আর সারিটা
             * সবার উপরে বসেছিল। ⓘ এক ক্লিকের সুবিধাটা তবু যায়নি:
             * ড্যাশবোর্ডের নিজের টাইলেই ইনভয়েস তালিকা আছে
             * ([[SalesDashboard]])। তাই মেনু ধারা মানে, আর দ্রুত-পথটা
             * ড্যাশবোর্ডে থাকে।
             */
            ['label' => 'sales::menu.orders', 'icon' => 'book', 'route' => 'sales.order.index', 'permission' => 'sales.order.view',
                'setting' => 'sales.screen_orders'],

            /*
             * ⭐ আদেশের খোঁজ — মালিকের চাওয়া, ১৯ সেপ্টেম্বর ২০২৬।
             *
             * ⓘ আদেশের সারির ঠিক পরে: প্রশ্নটা ("আমার আদেশটা কোথায়")
             * আদেশ দেখার পরেই ওঠে। ⚠️ একই সুইচে (`screen_orders`) —
             * যে ডিপো আদেশের পর্দা বন্ধ রাখে, তার খোঁজার পাতাও লাগে না।
             */
            ['label' => 'sales::menu.order_track', 'icon' => 'search', 'route' => 'sales.order.track',
                'permission' => 'sales.order.view', 'setting' => 'sales.screen_orders'],

            ['label' => 'sales::menu.direct', 'icon' => 'sales', 'route' => 'sales.direct.create', 'permission' => 'sales.challan.create',
                'setting' => 'sales.screen_direct'],

            ['label' => 'sales::menu.challans', 'icon' => 'challan', 'route' => 'sales.challan.index', 'permission' => 'sales.challan.view',
                'setting' => 'sales.screen_challans'],

            /*
             * শিপমেন্ট — চালানের ঠিক নিচে, একই সুইচের পেছনে নয়।
             *
             * যে ডিপো নিজের গাড়িতে মাল পাঠায় না, তার ট্রিপের পর্দাও
             * লাগে না — তাই নিজের সুইচ। কিন্তু চালান বন্ধ থাকলে
             * ট্রিপেরও মানে নেই, আর সেটা সুইচ নয়, বাস্তবতা: তালিকায়
             * তোলার মতো কোনো চালানই থাকত না।
             */
            ['label' => 'sales::menu.shipments', 'icon' => 'share', 'route' => 'sales.shipment.index',
                'permission' => 'sales.shipment.view', 'setting' => 'sales.screen_shipments'],

            /*
             * ⓘ ইনভয়েস তালিকা — মাল যাওয়ার পরে, কারণ বিলটাও তখনই
             * সত্যি হয়। তালিকাটা সব বিল দেখায় (কাউন্টার, সরাসরি
             * বিক্রয়, চালান থেকে বানানো), কেবল বাতিলগুলো একটা বোতামের
             * পেছনে।
             *
             * ⛔ দুই মেনুতে একই তালিকা রাখা হয় না — মালিকের নিজের নিয়ম
             * (*"ekoi jinis dui jaygay dorkar nai"*)।
             */
            ['label' => 'sales::menu.invoices', 'icon' => 'receipt', 'route' => 'sales.invoice.index', 'permission' => 'sales.invoice.view'],

            /*
             * ⭐ "আদায়" বোতাম নেই — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬ (ক্রয়ের
             * "পরিশোধ"-এর মতোই): গ্রাহকের টাকা নেওয়া হিসাবের রসিদ ভাউচারের কাজ,
             * আর কাউন্টারের ডিপোজিটও এখন রসিদ ভাউচার। ⓘ পুরনো আদায়ের পাতা ও
             * রুট থাকল — ইতিহাস, চেক ফেরত আর বিলের পাতার লিংক ওখানেই খোলে।
             */
            ['label' => 'sales::menu.returns', 'icon' => 'refresh', 'route' => 'sales.return.index', 'permission' => 'sales.return.view'],

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
            ['label' => 'sales::menu.print_queue', 'icon' => 'printer', 'route' => 'sales.print_queue.index',
                'permission' => 'sales.invoice.view'],

            /*
             * ⓘ POS ও শিফ্ট তালিকার শেষে — মালিকের দেওয়া ক্রমে এই দুইটা
             * সারি নেই, আর কাগজের ধারাটা এক টানে পড়া যাওয়াই চাওয়া — মাঝখানে
             * কাউন্টারের দুইটা সারি পড়লে ধারাটা থেমে যেত।
             *
             * ⚠️ মালিকের তালিকায় এই দুইটা ছিল না, কারণ তাঁর ডিপোতে
             * POS-এর সুইচ বন্ধ — সারিগুলো তাঁর পর্দাতেই আসে না। ⛔ তাই
             * তুলে দেওয়া হয়নি; যে ব্যবসা POS চালায় তার কাছে ওটাই
             * দিনের প্রধান পর্দা।
             */
            ['label' => 'sales::menu.pos', 'icon' => 'cash', 'route' => 'sales.pos.index', 'permission' => 'sales.pos',
                'setting' => 'sales.screen_pos'],

            /*
             * শিফট — কাউন্টারের ঠিক নিচে, একই সুইচের পেছনে।
             *
             * যে ব্যবসায় কাউন্টারের পর্দাই নেই, তার ড্রয়ারের শিফটও নেই।
             */
            ['label' => 'sales::menu.shift', 'icon' => 'clock', 'route' => 'sales.shift.index', 'permission' => 'sales.pos',
                'setting' => 'sales.screen_pos'],
        ],
        'reports' => [
            ['label' => 'sales::menu.pending_orders', 'icon' => 'clock', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'pending-orders'], 'permission' => 'sales.report'],
            ['label' => 'sales::menu.undelivered', 'icon' => 'alert-triangle', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'uninvoiced'], 'permission' => 'sales.report'],
            ['label' => 'sales::menu.by_customer', 'icon' => 'customer', 'route' => 'sales.report.show',
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
            ['label' => 'sales::menu.by_product', 'icon' => 'inventory', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'by-product'], 'permission' => 'sales.report'],

            /*
             * ব্র্যান্ড ধরে — দুইশো পণ্যের তালিকায় যা চোখে পড়ে না,
             * বিশটা ব্র্যান্ডে পড়ে। আর দরকষাকষিটাও হয় ব্র্যান্ড ধরে।
             */
            ['label' => 'sales::menu.by_brand', 'icon' => 'star', 'route' => 'sales.report.show',
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
            ['label' => 'sales::menu.lot_trace', 'icon' => 'search', 'route' => 'sales.lot.trace',
                'permission' => 'sales.challan.view'],

            /*
             * লক্ষ্যমাত্রা — প্রতিবেদনের ভাগে, লেনদেনে নয়।
             *
             * এখানে কোনো কাগজ তৈরি হয় না; মাসে একবার সংখ্যা বসে আর
             * বাকি দিনগুলো দেখা হয় — সেটা প্রতিবেদনের স্বভাব।
             */
            ['label' => 'sales::target.title', 'icon' => 'scale', 'route' => 'sales.target.index',
                'permission' => 'sales.target.view'],

            /*
             * ডিলারের কমিশন — লক্ষ্যমাত্রার পাশে।
             *
             * দুইটাই মাস ধরে দেখা হয়, আর দুইটাই মাস শেষে মেলানো হয়।
             */
            /*
             * স্কিম — কমিশনের ঠিক আগে, আর সেটাই ক্রম।
             *
             * স্কিম বলে হার কত হওয়ার কথা; কমিশনের দাবি বলে কত সত্যিই
             * দেওয়া হলো। আগে কেবল দ্বিতীয়টা ছিল, তাই "এই হারটা কে ঠিক
             * করল" প্রশ্নের উত্তর ছিল একজন মানুষের স্মৃতি।
             */
            ['label' => 'sales::menu.schemes', 'icon' => 'megaphone', 'route' => 'sales.scheme.index',
                'permission' => 'sales.scheme.view'],
            ['label' => 'sales::menu.commission', 'icon' => 'wallet', 'route' => 'sales.commission.index',
                'permission' => 'sales.commission.view'],
            ['label' => 'sales::menu.deposit_claims', 'icon' => 'attachment', 'route' => 'sales.claim.index',
                'permission' => 'sales.claim.view'],
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

        /*
         * ট্রিপের চাবি চালানের চাবি থেকে আলাদা।
         *
         * চালান লেখেন বিক্রয়ের লোক; গাড়ি কে চালাবে, কোন চালান কোন
         * গাড়িতে উঠবে আর সন্ধ্যায় কী ফিরল — ওটা গুদামের কাজ। একই
         * চাবিতে রাখলে হয় গুদামের লোককে চালান কাটার অধিকার দিতে হত,
         * নয় বিক্রয়ের লোককে গাড়ির হিসাব বুঝে নিতে।
         */
        'sales.shipment.view',
        'sales.shipment.create',
        'sales.shipment.cancel',

        /*
         * টার্গেট দেখা আর বসানো — দুইটা আলাদা চাবি।
         *
         * নিজের টার্গেট নিজে বদলাতে পারলে ওটা আর টার্গেট নয়, ইচ্ছা।
         */
        'sales.target.view',
        'sales.target.manage',

        /*
         * ডিলারের কমিশন — দেখা, দেওয়া, আর সীমা ছাড়ানো।
         *
         * সীমা ছাড়ানোর চাবিটা আলাদা, আর ঢালাও `sales.%` নিয়মে যেন
         * বিক্রয়কর্মীর হাতে না পড়ে সেজন্য সিডারের বাদ-তালিকাতেও আছে।
         * এই ফাঁদটা এই প্রকল্পে তিনবার ধরা পড়েছে।
         */
        /*
         * জমার দাবি — দেখা আর সিদ্ধান্ত দেওয়া আলাদা।
         *
         * দেখা রোজকার; গ্রহণ করা মানে খাতায় টাকা বসানো। এক চাবি হলে
         * যে কেউ তালিকা খুলে সব দাবি গ্রহণ করে দিতে পারতেন।
         */
        'sales.claim.view',
        'sales.claim.decide',

        'sales.scheme.view',
        'sales.scheme.manage',
        'sales.commission.view',
        'sales.commission.manage',
        'sales.commission.override',
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

    /*
     * নতুন ইনস্টলে কোন রোল বিক্রয়ের কোন অনুমতি নিয়ে শুরু করবে (§৫ রোল-টেবিল)।
     * শুরুর সারি, তালা নয় — ক্রেতা RoleController-এ বদলাতে পারেন।
     *
     * Field Sales (SR/SO/TSO/ASM — মাঠের বিক্রয়): অফলাইনে অর্ডার ও আদায়,
     * মোবাইলের মূল কাজ। এক নমুনা-রোল, চারটা নকল নয় — ক্রেতা চাইলে পদভেদে
     * আলাদা রোল বানাবেন (মালিকের নিয়ম: সারি, তালা নয়)।
     * Manager: দেখা ও রিপোর্ট, বানানো নয় (তদারকি; create কেরানির)।
     */
    'role_templates' => [
        'Field Sales' => [
            'sales.order.view', 'sales.order.create',
            'sales.collection.view', 'sales.collection.create',
        ],
        'Manager' => [
            'sales.order.view', 'sales.challan.view', 'sales.invoice.view',
            'sales.collection.view', 'sales.return.view', 'sales.shipment.view',
            'sales.report',
        ],
    ],

    'doc_types' => [
        'SO' => 'sales::doc.order',
        'DC' => 'sales::doc.challan',
        'TRP' => 'sales::doc.shipment',
        'INV' => 'sales::doc.invoice',
        'COL' => 'sales::doc.collection',
        'SR' => 'sales::doc.return',
        'CMC' => 'sales::doc.commission',
    ],

    /*
     * ⭐ যে কাগজগুলো নিশ্চিত হলেই খাতায় ওঠার কথা (২১ সেপ্টেম্বর ২০২৬)।
     *
     * ⚠️ আদেশ ও চালান এখানে নেই, আর সেটা ইচ্ছাকৃত: ওগুলো খতিয়ানে ওঠে
     * না। ⛔ ওদের "আটকে আছে" বললে তালিকাটা রোজ মিথ্যা বলত।
     *
     * ⓘ আগে এই নামগুলো [[PostingBacklog]]-এ হাতে লেখা ছিল, অর্থাৎ
     * accounts এই মডিউলের ভিতরে হাত দিত — অথচ তীরটা উল্টো দিকের।
     */
    'posts_to_the_books' => [
        'sales_invoice' => SalesInvoice::class,
        'sales_return' => SalesReturn::class,
        'collection' => Collection::class,
    ],

    'drill_sources' => [
        'commission_claim' => CommissionClaim::class,
        'sales_order' => SalesOrder::class,
        'delivery_challan' => DeliveryChallan::class,
        'shipment' => Shipment::class,
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
     * নিজের ঘটনা নিজেই শোনা — রান্নাঘরের টিকিট।
     *
     * ── কেন `confirm()`-এর ভেতরে নয় ─────────────────────────────────
     * টিকিটটা বিলের অংশ নয়। রান্নাঘরের সার্ভিস ব্যতিক্রম ছুড়লে বিলটা
     * ফিরে যাওয়া উচিত নয় — খাবারের অর্ডার আটকে দেওয়া আর টাকার হিসাব
     * ভুল হওয়া এক জিনিস নয়।
     *
     * ── কেন এটা মজুদে ছিল না, আর এখানে এল ───────────────────────────
     * প্রথমে শ্রোতাটা `Inventory/Listeners/`-এ লেখা হয়েছিল, এই ভুল
     * ধারণায় যে মজুদ বিক্রয়কে চেনে। চেনে না — মজুদের `depends_on`-এ
     * বিক্রয় নেই, আর [[BoundariesTest]] সেটাই ধরল। উল্টো ঘোষণাটা
     * চক্র বানাত, আর রেজিস্ট্রি বুট-টাইমেই ছুড়ে ফেলত।
     *
     * নির্ভরতার তীর যেদিকে সত্যি, ফাইলটাও সেদিকে।
     */
    /*
     * ⚠️ রান্নাঘরের শ্রোতাটা এখানে ছিল, এখন রেস্টুরেন্ট মডিউলে
     * (২ সেপ্টেম্বর ২০২৬)। রান্নাঘর মজুদের ভেতরে থাকার সময় এটা
     * বিক্রয়ে থাকাই ঠিক ছিল — বিক্রয় মজুদকে চেনে। এখন রান্নাঘর
     * রেস্টুরেন্টে, আর রেস্টুরেন্ট বিক্রয়কে চেনে, তাই শ্রোতাটাও
     * সেখানে। **বিক্রয় রেস্টুরেন্টকে চেনে না, চেনার দরকারও নেই।**
     */

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
    'dashboard' => SalesDashboard::class,

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
     * "সদ্য কী হয়েছে" — বিক্রয়ের দিক থেকে।
     *
     * দিনের শুরুতে মালিকের প্রথম প্রশ্ন "আমি না থাকতে কী কী হলো"।
     * আজ পর্যন্ত সেটার উত্তর পেতে চারটা তালিকা আলাদা করে খুলতে হত।
     */
    'activity' => [
        SalesActivity::class,
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
        /*
         * ⭐ ফেরত ও আদায় — মালিকের সিদ্ধান্ত, ১৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ দুইটার মিল একটাই: **টাকা বা মাল ফিরে আসে, আর
         * খাতা দেখে বোঝার উপায় থাকে না**। ⚠️ একটা মিথ্যা ফেরতে
         * বিক্রি মুছে যায়; কম লেখা আদায়ে টাকা পথেই থেকে যায়।
         *
         * ⚠️ সারি দুইটা কারো আজকের কাজ থামায় না — ছক না বসানো
         * পর্যন্ত সব আগের মতোই চলে।
         */
        'return' => 'sales::approval.return',
        'order' => 'sales::approval.order',
        'challan' => 'sales::approval.challan',
        'collection' => 'sales::approval.collection',
        'discount' => 'sales::approval.discount',
    ],

    /*
     * ⛔ যে কাজগুলোতে **টাকা নড়ে** — ২৪ সেপ্টেম্বর ২০২৬।
     *
     * ⭐ মালিকের সিদ্ধান্ত: এগুলোতে **একসাথে সই দেওয়া যায় না**
     * ([[BulkApproval]]) — একটা একটা করে দেখে দিতে হবে।
     *
     * ⓘ আদায় — টাকা গ্রহণ খাতায় বসে।
     *
     * ⚠️ নামগুলো `approvals`-এ থাকতেই হবে — [[ModuleDefinition]]
     * মিলিয়ে দেখে। ⛔ একটা টাইপো নীরবে কাগজটাকে bulk-এ
     * ঢুকিয়ে দিত।
     */
    'moves_money' => ['collection'],

    /*
     * ⭐ নতুন কোম্পানির বিক্রয়-সুইচ — ২৩ সেপ্টেম্বর ২০২৬।
     *
     * ⓘ মালিকের নিয়ম: নির্ধারিত দামের নিচে এন্ট্রি নয়। ⚠️ ডিফল্টে বসালে
     * **চলতি** ইনস্টলগুলোও থেমে যায় (মেপে দেখা: ২৫টা লাল), তাই নিয়মটা
     * কোম্পানি খোলার দিনে বসে — কারণসহ [[SalesDefaults]]-এ।
     */
    'provisions' => [
        SalesDefaults::class,
    ],

    'settings' => [
        /*
         * ⭐ কোন কাগজে ছাপা হবে — মালিকের সিদ্ধান্ত, যন্ত্রের নয়।
         *
         * ⓘ মালিকের কথা, ২০ সেপ্টেম্বর ২০২৬: *"কি কাগজে প্রিন্ট করবো এটা
         * নিজে নির্ধারণ করে দিব"*। ⚠️ আগে ঠিকানায় মাপ না থাকলে প্রতিটা
         * কন্ট্রোলার নিজে থেকে A4 ধরে নিত, আর বদলানোর কোনো পথ ছিল না।
         *
         * ⓘ প্রতিটা কাগজের নিজের ঘর, কারণ একই দোকানে বিল যায় রোলে আর
         * ভাউচার যায় A4-তে। ⛔ একটা মাত্র ডিফল্ট দিলে তার একটাকে বাঁচাতে
         * গিয়ে অন্যটা প্রতিবার হাতে বদলাতে হত।
         */
        [
            'key' => 'sales.print.paper.invoice',
            'label' => 'sales::settings.paper_invoice',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        [
            'key' => 'sales.print.paper.challan',
            'label' => 'sales::settings.paper_challan',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        [
            'key' => 'sales.print.paper.order',
            'label' => 'sales::settings.paper_order',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        [
            'key' => 'sales.print.paper.receipt',
            'label' => 'sales::settings.paper_receipt',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        /*
         * দাম কতটা সরতে পারে, আর সরলে কী।
         *
         * ---- কেন এটা লাগল, ৩০ আগস্ট ২০২৬ ----
         * আজকের নিয়মটা ভোঁতা: **যেকোনো** ছাড়েই অনুমোদন লাগে -- দশ
         * টাকার ছাড়েও, দশ হাজারেরও।
         *
         * ফল দুইদিকেই খারাপ। কাউন্টারে পাঁচ টাকার ছাড় দিতে গিয়ে বিল
         * আটকে থাকে, তাই লোকে ছাড় দেওয়াই বন্ধ করে -- বা আরও খারাপ,
         * **দর কমিয়ে লেখে** যাতে ছাড়ের ঘরটা ছুঁতে না হয়। তখন খাতায়
         * ছাড়টা আর দেখাই যায় না।
         *
         * এই নিয়মটা মাপে সারির **দর**, ছাড়ের ঘর নয় -- দর কমিয়ে লেখার
         * পথটাই বন্ধ করে।
         */
        [
            'key' => 'sales.price_tolerance_percent',
            'label' => 'sales::settings.price_tolerance_percent',
            'type' => 'integer',
            'default' => 0,
            'group' => 'entry',
        ],
        [
            /*
             * ⛔ ডিফল্ট "আটকাও" — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।
             *
             * তাঁর কথা: *"nirdarito sales price er niche entry nibe na"*।
             *
             * ── ⓘ আগে এখানে যা লেখা ছিল, আর কেন সেটা বদলাল ────────────
             * পুরনো যুক্তি: *"যে কোম্পানি কোনোদিন সীমা বসায়নি, সে কাউকে
             * থামাতে বলেনি; কড়া ডিফল্ট দিলে আপগ্রেডের দিন সকালে প্রতিটা
             * কাউন্টার থেমে যেত"*। ⚠️ যুক্তিটা এখনো সত্যি, কিন্তু মালিক
             * এখন **উল্টোটাই চেয়েছেন**, আর এটা তাঁরই ব্যবস্থা।
             *
             * ⓘ থেমে যাওয়ার ঝুঁকিটা এখানে ছোট: সহনশীলতা ০%, অর্থাৎ
             * নির্ধারিত দামেই বেচা যায় — কেবল **তার নিচে** নয়। আর যে
             * পণ্যের কোনো দাম বসানো নেই, তার কোনো সীমাও নেই।
             *
             * ⛔ ডিফল্টটা বদলানো হয়েছিল, আর মেপে দেখে **ফিরিয়ে আনা হলো**।
             *
             * ⓘ `block` ডিফল্ট করামাত্র বিক্রয়ের সুইটে **২৫টা লাল** — প্রতিটা
             * বার্তা এক: *"দরটা মান দাম থেকে % এর বেশি সরে গেছে"*। ⚠️ অর্থাৎ
             * উপরের পুরনো যুক্তিটা অনুমান ছিল না, **মাপা সত্য**: কড়া ডিফল্ট
             * দিলে আপগ্রেডের সকালে প্রতিটা কাউন্টার থেমে যায়।
             *
             * ⭐ মালিকের নিয়মটা তাই **ডিফল্টে নয়, তাঁর কোম্পানির সুইচে** বসানো
             * হয়েছে — যেখানে তিনি চেয়েছেন ঠিক সেখানেই কাজ করে, আর যে ইনস্টল
             * কোনোদিন সীমা বসায়নি তার কিছু বদলায় না।
             *
             * ⓘ পর্দা থেকেই বদলানো যায়: বিক্রয় → এন্ট্রি → দামের নীতি।
             */
            'key' => 'sales.price_policy',
            'label' => 'sales::settings.price_policy',
            'type' => 'string',
            'default' => 'allow',
            'group' => 'entry',
            'options' => [
                'allow' => 'sales::price_policy.allow',
                'warn' => 'sales::price_policy.warn',
                'block' => 'sales::price_policy.block',
            ],
        ],
        [
            /*
             * নিচে আর উপরে আলাদা সুইচ।
             *
             * মান দামের নিচে বেচলে টাকা যায়; উপরে বেচলে গ্রাহক যায়।
             * কিছু ডিপো কেবল প্রথমটা পাহারা দেয় -- দ্বিতীয়টা তাদের
             * কাছে বিক্রয়কর্মীর কৃতিত্ব।
             */
            'key' => 'sales.price_policy_below',
            'label' => 'sales::settings.price_policy_below',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.price_policy_above',
            'label' => 'sales::settings.price_policy_above',
            'type' => 'boolean',
            /*
             * ⭐ উপরে বেচা আটকায় না — মালিক কেবল **নিচের** কথা বলেছেন।
             *
             * ⓘ উপরের মন্তব্যটাই কারণ: নিচে বেচলে টাকা যায়, উপরে বেচলে
             * ওটা বিক্রয়কর্মীর কৃতিত্ব। ⛔ দুইটাই আটকালে নীতিটা `block`
             * হওয়ামাত্র বেশি দামে বেচাও থেমে যেত, আর মালিক সেটা চাননি।
             */
            'default' => false,
            'group' => 'entry',
        ],
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
             * কমিশনের টাকার সীমা — এর উপরে গেলে আটকায়।
             *
             * শূন্য মানে "সীমা নেই"। দুইটা সীমাই লাগে: শতাংশ মাত্র ২%
             * হলেও অঙ্কটা ৫ লাখ হতে পারে, আর তখন শতাংশের সীমা কিছুই
             * ধরত না।
             */
            'key' => 'sales.commission_max_amount',
            'label' => 'sales::settings.commission_max_amount',
            'type' => 'number',
            'default' => 5000,
            'group' => 'limits',
        ],
        [
            /*
             * রাউন্ডিং কতটুকু পর্যন্ত — মালিকের নির্দেশ (৩ সেপ্টেম্বর ২০২৬)।
             *
             * ── কেন সীমা ছাড়া ঘরটা বিপজ্জনক ──────────────────────────
             * "রাউন্ডিং" নামটা বলে পয়সার ভগ্নাংশ মেলানো — ৪,৩০০.৪০ থেকে
             * ৪,৩০০। কিন্তু ঘরটায় সীমা না থাকলে ওখানে **৪৩০ টাকাও বসানো
             * যায়**, আর তখন ওটা আসলে একটা ছাড় — কেবল ছাড়ের ঘর এড়িয়ে।
             *
             * ⚠️ **আর সেটাই সবচেয়ে খারাপ ফল:** ছাড়ের নিজের অনুমোদনের নিয়ম
             * আছে, সীমা আছে, রিপোর্ট আছে। রাউন্ডিংয়ের কিছুই নেই। **যে
             * ছাড় রাউন্ডিং সেজে যায়, সেটা কোনো রিপোর্টেই ধরা পড়ে না।**
             *
             * শূন্য মানে "সীমা নেই" — বাকি সীমাগুলোর মতোই। ⓘ ৫ টাকা
             * ডিফল্ট, কারণ পয়সা মেলাতে তার বেশি কখনো লাগে না।
             */
            'key' => 'sales.rounding_max',
            'label' => 'sales::settings.rounding_max',
            'type' => 'number',
            'default' => 5,
            'group' => 'limits',
        ],
        [
            /*
             * কমিশনের হারের সীমা — বিলের অঙ্কের শতাংশে।
             *
             * ৫০% কমিশনও বৈধ; সীমাটা নিষেধ নয়, কেবল "কাউকে দেখে সই
             * করতে হবে" বলার উপায়।
             */
            'key' => 'sales.commission_max_percent',
            'label' => 'sales::settings.commission_max_percent',
            'type' => 'number',
            'default' => 10,
            'group' => 'limits',
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

        [
            'key' => 'sales.screen_shipments',
            'label' => 'sales::settings.screen_shipments',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
            'holds' => Shipment::class,
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

        /*
         * ⭐ কাউন্টারের নিজের সুইচ — মালিকের নির্দেশ, ২৩ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ তাঁর কথা: *"রাউন্ডিং শুধু পজ এ"*, তারপর *"পস-এ যোগ করো,
         * সরাসরি বিক্রয়েও থাক"*, আর শেষে *"সুইচ দাও রাউন্ডিং এর জন্য"*।
         *
         * ── ⚠️ কেন উপরেরটার সাথে ভাগ করা হয়নি ───────────────────────
         * ⛔ একটা সুইচ দুই পর্দায় চালালে তার একটাকে বাঁচাতে গিয়ে অন্যটা
         * নষ্ট হত: ডিপোর সরাসরি বিক্রয়ে পয়সা মেলানো দরকার হয়, আর
         * কাউন্টারে কেউ কেউ ওটা চান না — ⓘ ঠিক এই কারণেই ছাপার কাগজও
         * কাগজ ধরে ধরে বসানো ([[PrintProfile]])।
         *
         * ⓘ ডিফল্টে **বন্ধ**: আজ পর্যন্ত পস-এ রাউন্ডিং ছিলই না, তাই
         * চালু দিলে প্রতিটা চালু কাউন্টারে হঠাৎ একটা নতুন ঘর গজাত।
         */
        [
            'key' => 'sales.pos_rounding',
            'label' => 'sales::settings.pos_rounding',
            'type' => 'boolean',
            'default' => false,
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
        /*
         * পরিবহন ও চালান-গন্তব্য — সরাসরি বিক্রয়ের পর্দার দুইটা প্যানেল।
         *
         * যে ডিপো কাউন্টার থেকে হাতে হাতে মাল দেয় তার কাছে গাড়ি, চালক
         * বা ঠিকানার কোনো মানে নেই — প্রতিটা চালানে দুইটা বোতাম পার
         * করতে হত। নিয়ম ৭: প্রতিটা ঐচ্ছিক ঘরের নিজের সুইচ।
         */
        [
            'key' => 'sales.field_transport',
            'label' => 'sales::settings.field_transport',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            'key' => 'sales.field_shipment',
            'label' => 'sales::settings.field_shipment',
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

    /*
     * পোর্টালের গার্ড সেশন থেকে গ্রাহককে তোলে কোম্পানি বসার আগে,
     * তাই ওই একটামাত্র কোয়েরি টেন্যান্ট ছাঁকনির বাইরে চলতে হয়।
     * পুরো ব্যাখ্যা `CustomerProvider`-এ; config/auth.php-র
     * `customers` প্রোভাইডার এই নামটাই ব্যবহার করে।
     */
    'auth_providers' => [
        'customers' => CustomerProvider::class,
    ],
    /*
     * যে ঘরগুলো সবাই দেখবে না।
     *
     * `cost_of_goods` আগে থেকেই ঢাকা ছিল, কিন্তু **হাতে লেখা একটা
     * শর্ত দিয়ে** — বিলের পর্দায় একটা `can()`। সেটা কাজ করত ঠিকই,
     * কিন্তু পরের পর্দাটা লেখার দিনে কেউ ওই লাইনটা কপি করতে ভুলে
     * গেলে ঘরটা নীরবে খুলে যেত, আর কোনো পরীক্ষা কিছু বলত না।
     *
     * এখন ঘোষণাটা এখানে, আর একটা পাহারা মিলিয়ে দেখে
     * ([[NoSensitiveFieldIsPrintedInTheOpenTest]])।
     *
     * সারির `unit_cost`ও এখানে: বিলের মোট ব্যয় ঢেকে সারির ব্যয় খোলা
     * রাখলে যোগ করলেই পুরোটা বেরিয়ে আসত।
     */
    'sensitive_fields' => [
        SalesInvoice::class => [
            'cost_of_goods' => 'sales.cost.view',
        ],
        SalesInvoiceLine::class => [
            'unit_cost' => 'sales.cost.view',
        ],
    ],

];
