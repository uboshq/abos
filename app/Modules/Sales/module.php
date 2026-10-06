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
use App\Modules\MasterData\Models\OpportunityStage;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\Shipment;
use App\Modules\Sales\Panels\SalesFacts;
use App\Modules\Sales\Reports\RouteReports;
use App\Modules\Sales\Reports\SalesChannelReports;
use App\Modules\Sales\Reports\SalesReports;
use App\Modules\Sales\Support\InvoiceDesigns;
use App\Modules\Sales\Support\PaperDesigns;

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

        // ⭐ গ্রাহক তালিকার 👁 — এক নজরের সারাংশের বিল ও জমার অংশ (মালিক, ২৭ সেপ্টেম্বর ২০২৬)
        \App\Core\Contracts\CustomerTrade::class => \App\Modules\Sales\Services\SalesCustomerTrade::class,

        // ⭐ গ্রাহকের তালিকার টপ/বটম বিক্রি আর "ভালো কাস্টমার" (মালিক, ১ অক্টোবর ২০২৬)
        \App\Core\Contracts\CustomerSalesFilters::class => \App\Modules\Sales\Services\SalesCustomerFilters::class,
        // ⓘ হোমের ছাঁকনির গুদাম, এলাকা আর SR — বিক্রয় জানে (মালিক, ৪ অক্টোবর ২০২৬; [[HomeSalesFilters]])
        \App\Core\Contracts\HomeSalesFilters::class => \App\Modules\Sales\Services\SalesHomeFilters::class,
        // ⓘ রসিদের "কোন বিলের বিপরীতে" — গ্রাহকের খোলা বিল, বিলের নিজের বাকিতে (Accounts-Finance অডিট ম১)
        \App\Core\Contracts\PartyOpenBills::class => \App\Modules\Sales\Services\SalesPartyOpenBills::class,

        // ⛔ কুপন কেবল পাকা কাগজের সত্যিকারের সারিতে — প্রমোশন বিক্রয়কে চেনে না, চুক্তি চেনে (গভীর অডিট, ২৯ সেপ্টেম্বর ২০২৬)
        \App\Core\Contracts\CouponPapers::class => \App\Modules\Sales\Services\SalesCouponPapers::class,

        // ⭐ সইয়ে আটকে থাকা কাউন্টারের বিক্রি এক পাতায়, এক ক্লিকে নিশ্চিত (মালিক, ২৮ সেপ্টেম্বর ২০২৬)
        \App\Core\Contracts\ApprovalBundles::class => \App\Modules\Sales\Services\CounterSaleBundle::class,
    ],

    'menu' => [
        'dashboard' => [
            ['label' => 'sales::dashboard.title', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'sales'], 'permission' => 'sales.invoice.view'],
            ['label' => 'sales::overview.title', 'icon' => 'reports', 'route' => 'sales.overview',
                'permission' => 'sales.report'],
        ],

        'transactions' => [
            /*
             * ⭐ আন্তর্জাতিক ধারার ক্রম — মালিক, ৪ অক্টোবর ২০২৬ (সমন্বয়কের পরিকল্পনা): CRM → দরপত্র → বিক্রয় আদেশ →
             * ডেলিভারি অর্ডার → সরাসরি বিক্রয় → ডেলিভারি প্রসেসিং → বিল ও চালান → ট্র্যাকিং → ফেরত → দাম → POS ও শিফট।
             * ⓘ কেবল ক্রম আর ভাঁজ; রুট, চাবি আর পাতা যেমন ছিল। নিচের ব্লকগুলো সেই ক্রমে বসানো (menu_order.py)।
             */
            /*
             * ⭐ লিড ও সুযোগ — NEXUS §৭। ⓘ কাগজের ধারার শুরুতে: খোঁজ → সুযোগ → আদেশ।
             */
            ['label' => 'sales::crm.leads', 'icon' => 'customer', 'route' => 'sales.lead.index',
                'permission' => 'sales.lead.view'],
            ['label' => 'sales::crm.pipeline', 'icon' => 'star', 'route' => 'sales.opportunity.pipeline',
                'permission' => 'sales.opportunity.view'],

            /*
             * ⭐ উদ্ধৃতি আর বিক্রয় আদেশ — ড্যাশবোর্ডের ঠিক পরে, দুইটা ভাঁজে (মালিকের
             * নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"age bosaw, code pore korbo"*)।
             * ⭐ ৪ অক্টোবর ২০২৬ (মালিকের আন্তর্জাতিক পরিকল্পনা): উদ্ধৃতির চার সারি এখন আসল পাতা — নতুন, তালিকা (ট্যাবসহ),
             * তুলনা, সংস্করণ — উদ্ধৃতির নিজের চাবি আর নিজের সুইচে। ⓘ পুরনো `sales.planned` ঠিকানা ঠিক পাতায় নামে
             * ([[PlannedScreenController::MOVED]])। আগের পঞ্চম "উদ্ধৃতি" সারি (একই তালিকায় যেত) উঠে গেছে —
             * একই পাতা দুই সারিতে নয় (মালিকের নিয়ম)। যে ডিপো দর লিখে দেয় না, তার মেনুতে ভাঁজটাই আসে না (নিয়ম ৭)।
             */
            ['label' => 'sales::planned.quotation_new', 'cluster' => 'quotations', 'icon' => 'book', 'route' => 'sales.quotation.create',
                'permission' => 'sales.quotation.create', 'setting' => 'sales.screen_quotations'],
            ['label' => 'sales::planned.quotation_list', 'cluster' => 'quotations', 'icon' => 'book', 'route' => 'sales.quotation.index',
                'permission' => 'sales.quotation.view', 'setting' => 'sales.screen_quotations'],
            ['label' => 'sales::planned.quotation_compare', 'cluster' => 'quotations', 'icon' => 'book', 'route' => 'sales.quotation.compare',
                'permission' => 'sales.quotation.view', 'setting' => 'sales.screen_quotations'],
            ['label' => 'sales::planned.quotation_revision', 'cluster' => 'quotations', 'icon' => 'book', 'route' => 'sales.quotation.revisions',
                'permission' => 'sales.quotation.view', 'setting' => 'sales.screen_quotations'],

            /* ⭐ পুরনো আদেশের পাতা এই ভাঁজে — মালিকের সিদ্ধান্ত, ২৮ সেপ্টেম্বর ২০২৬: "ডেলিভারি অর্ডার"
               এখন প্রতিটা বিক্রির চালান ([[DeliveryOrderTabs]]), আর আদেশ নিজের নামে ফিরল। */
            ['label' => 'sales::planned.order_new', 'cluster' => 'sales_orders', 'icon' => 'receipt', 'route' => 'sales.order.create',
                'permission' => 'sales.order.create', 'setting' => 'sales.screen_orders'],
            ['label' => 'sales::planned.order_list', 'cluster' => 'sales_orders', 'icon' => 'receipt', 'route' => 'sales.order.index',
                'permission' => 'sales.order.view', 'setting' => 'sales.screen_orders'],

            /*
             * ⭐ অপেক্ষমাণ, আংশিক, ব্যাক অর্ডার, ইতিহাস — এখন "অর্ডার তালিকা"-র ওপরের ট্যাব, মেনুর আলাদা সারি নয়
             * (নকশার পর্যালোচনা, ধাপ ৭-এর ২; মালিক, ১ অক্টোবর ২০২৬: *"ok kore daw"*)। ⓘ ২৮ সেপ্টেম্বরের
             * "মেনুতে এখন, কোড পরে" সারিগুলো একই তালিকার ছাঁকনি ছিল; এখন সত্যিকারের ট্যাব ([[OrderTracking::LIST_TABS]]),
             * আর পুরনো ঠিকানাগুলো ঠিক ট্যাবে নামে ([[PlannedScreenController::FOLDED]])। ভাঁজে থাকে নতুন অর্ডার,
             * অর্ডার তালিকা; ট্র্যাকিং নিজের সারিতে।
             */
            /*
             * ⭐ মেনুর ক্রম — মালিকের নির্দেশ, ১ অক্টোবর ২০২৬ (আগের রাতের পরিকল্পনা, "যেটা প্ল্যান হয়েছিল সেভাবেই"):
             * ড্যাশবোর্ড → উদ্ধৃতি → অর্ডার → নতুন DO → DO তালিকা → সরাসরি বিক্রয় → ইনভয়েস তালিকা → ডেলিভারি চালান
             * → ডেলিভারি প্রসেসিং → মূল্য নির্ধারণ → বিক্রয় ফেরত → যে কাগজ বেরোয়নি।
             * ⓘ "DO তালিকায় DO মেনু ভাঁজ করা থাকবে" — DO-র ধাপগুলো ([[DeliveryOrderTabs]]) এক ভাঁজে। কেবল ক্রম আর
             * নাম বদলেছে; প্রতিটা সারির রুট আর চাবি আগের মতোই।
             */
            /*
             * ⭐ "Delivery Order (DO)" ভাঁজ — মালিক, ২ অক্টোবর ২০২৬: *"নতুন DO = ernam hobe 'Delivery Order' … Er vitore
             * order er moto vaj thakbe"*। পাঁচ সারি, অর্ডারের ভাঁজের মতো; নতুন DO লেখার বোতাম তালিকার পাতার ভিতরে
             * (*"Er vitorei thakbe DO Creat & List"*)। DO-র ধাপের ট্যাবগুলো (খসড়া, সই, অপেক্ষা …) তালিকার পাতাতেই থাকে।
             */
            // ⓘ আসল DO কাগজের ডেস্কে — ৩ অক্টোবর ২০২৬ ([[DeliveryOrderDeskController]]); আংশিক আর ব্যাক আসবে abos-86-এর মজুদ-আটকানোর সাথে
            // ⭐ বিক্রয় আদেশ DO-র কাজ নিলে (`sales.orders_replace_do`) ভাঁজটা মেনু থেকে সরে — খোলা DO-র পাতা আর লিংক চলে (৪ অক্টোবর ২০২৬)
            ['label' => 'sales::planned.do_list', 'cluster' => 'delivery_orders', 'icon' => 'book', 'route' => 'sales.delivery_order.index',
                'permission' => 'sales.do.view', 'hidden_when' => 'sales.orders_replace_do'],
            ['label' => 'sales::planned.do_pending', 'cluster' => 'delivery_orders', 'icon' => 'book', 'route' => 'sales.delivery_order.index',
                'route_params' => ['tab' => 'pending'], 'permission' => 'sales.do.view', 'hidden_when' => 'sales.orders_replace_do'],
            ['label' => 'sales::planned.do_partial', 'cluster' => 'delivery_orders', 'icon' => 'book', 'route' => 'sales.delivery_order.index',
                'route_params' => ['tab' => 'partial'], 'permission' => 'sales.do.view', 'hidden_when' => 'sales.orders_replace_do'],
            ['label' => 'sales::planned.do_back', 'cluster' => 'delivery_orders', 'icon' => 'book', 'route' => 'sales.delivery_order.index',
                'route_params' => ['tab' => 'back'], 'permission' => 'sales.do.view', 'hidden_when' => 'sales.orders_replace_do'],
            ['label' => 'sales::planned.do_history', 'cluster' => 'delivery_orders', 'icon' => 'book', 'route' => 'sales.delivery_order.index',
                'route_params' => ['tab' => 'history'], 'permission' => 'sales.do.view', 'hidden_when' => 'sales.orders_replace_do'],

            /* ⭐ সরাসরি বিক্রয় — মাঝের সব ধাপ এক চাপে, সোজা বিলে (মালিক, ২৮ সেপ্টেম্বর ২০২৬) */
            ['label' => 'sales::menu.direct', 'cluster' => 'direct_sale', 'icon' => 'sales', 'route' => 'sales.direct.create', 'permission' => 'sales.challan.create',
                'setting' => 'sales.screen_direct'],
            /* ⭐ ডিপোর যাচাই — হিসাবে অনুমোদিত DO থেকে সরাসরি বিক্রয়ে (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬, ধাপ ঙ) */
            ['label' => 'sales::counter_source.menu', 'cluster' => 'direct_sale', 'icon' => 'search', 'route' => 'sales.direct.depot_check',
                'permission' => 'sales.challan.create', 'setting' => 'sales.screen_direct'],
            /*
             * ⓘ ইনভয়েস তালিকা — সব বিল (কাউন্টার, সরাসরি বিক্রয়, চালান থেকে বানানো), কেবল বাতিলগুলো একটা বোতামের পেছনে।
             * ⛔ দুই মেনুতে একই তালিকা রাখা হয় না — মালিকের নিজের নিয়ম (*"ekoi jinis dui jaygay dorkar nai"*)।
             */
            /*
             * ⭐ "Billing Documents" ভাঁজ — মালিক, ২ অক্টোবর ২০২৬: *"ইনভয়েস তালিকা ও ডেলিভারি চালান টার ভিতরে ভাজ হয়ে
             * থাকবে — খসরা তালিকা, ইনভয়েস তালিকা, ডেলিভারি চালান তালিকা"*। কেবল জায়গা বদল; রুট আর চাবি আগের মতো।
             */
            ['label' => 'sales::menu.direct_drafts', 'cluster' => 'direct_sale', 'icon' => 'receipt', 'route' => 'sales.direct.drafts',
                'permission' => 'sales.challan.create', 'setting' => 'sales.screen_direct'],

            /*
             * ⭐ ডেলিভারি প্রসেসিং — পরিবহন বরাদ্দ, লোডিং শিট, গেট পাস, ডিসপ্যাচ রেজিস্টার, ডেলিভারি নিশ্চিতকরণ।
             * ⓘ যে পর্দা তৈরি হয়নি সে [[PlannedScreenController]]-এর সৎ পাতায় ("মেনুতে এখন, কোড পরে")।
             */
            /*
             * ⭐ চালান তালিকা ডেলিভারি প্রসেসিং-এর প্রথম সারি — মালিক, ৪ অক্টোবর ২০২৬ (*"etai ki int standard?"* → *"ok ekhoni
             * koro"*)। আন্তর্জাতিক মানে (SAP SD, D365) চালান = Outbound Delivery, মাল পাঠানোর কাগজ; বিলিং-এ থাকে কেবল বিলের
             * কাগজ। ⓘ ২ অক্টোবরের "বিলিং ভাঁজে চালান" সাজ এতে বদলাল; রুট আর চাবি আগের মতো।
             */
            ['label' => 'sales::menu.challan_list', 'cluster' => 'delivery_processing', 'icon' => 'challan', 'route' => 'sales.challan.index',
                'permission' => 'sales.challan.view', 'setting' => 'sales.screen_challans'],
            // ⭐ পরিবহন বরাদ্দ — আসল পাতা ([[TransportAssignmentController]], মালিক, ৩ অক্টোবর ২০২৬)
            ['label' => 'sales::planned.transport_assign', 'cluster' => 'delivery_processing', 'icon' => 'share', 'route' => 'sales.transport.index',
                'permission' => 'sales.challan.view', 'setting' => 'sales.screen_shipments'],
            // ⭐ লোডিং শিট — আসল পাতা ([[LoadingSheetController]], ২৯ সেপ্টেম্বর ২০২৬)
            ['label' => 'sales::loading.title', 'cluster' => 'delivery_processing', 'icon' => 'challan', 'route' => 'sales.loading_sheet.index',
                'permission' => 'sales.shipment.view', 'setting' => 'sales.screen_shipments'],
            // ⭐ গেট পাস — রওনার মুহূর্তে নিজে তৈরি, এখানে তালিকা ([[GatePassService]])
            ['label' => 'sales::gate_pass.title', 'cluster' => 'delivery_processing', 'icon' => 'challan', 'route' => 'sales.gate_pass.index',
                'permission' => 'sales.gate_pass.view'],
            // ⓘ ডিসপ্যাচ রেজিস্টার = ট্রিপের খাতা (শিপমেন্ট) — নিজের সুইচে, আগের মতো
            ['label' => 'sales::menu.dispatch_register', 'cluster' => 'delivery_processing', 'icon' => 'share', 'route' => 'sales.shipment.index',
                'permission' => 'sales.shipment.view', 'setting' => 'sales.screen_shipments'],
            ['label' => 'sales::menu.delivery_confirmation', 'cluster' => 'delivery_processing', 'icon' => 'check-circle', 'route' => 'sales.delivery.index',
                'permission' => 'sales.delivery.view', 'setting' => 'sales.screen_challans'],
            // ⭐ ডেলিভারির মাপকাঠি — OTIF, আদেশ থেকে রওনা, দেরির তালিকা ([[DeliveryPerformanceController]], ৪ অক্টোবর ২০২৬)
            ['label' => 'sales::delivery_performance.title', 'cluster' => 'delivery_processing', 'icon' => 'reports', 'route' => 'sales.delivery_performance.index',
                'permission' => 'sales.delivery.view', 'setting' => 'sales.screen_challans'],
            // ⓘ ৪ অক্টোবর ২০২৬: উপরের সারিতে আলাদা ট্যাব নয় — ডেলিভারি প্রসেসিং ভাঁজে, পরিবহনের শেষ ধাপের পরে
            ['label' => 'sales::tracking.title', 'cluster' => 'delivery_processing', 'icon' => 'search', 'route' => 'sales.tracking.index',
                'permission' => 'sales.order.view'],

            ['label' => 'sales::menu.invoices', 'cluster' => 'billing_documents', 'icon' => 'receipt', 'route' => 'sales.invoice.index',
                'permission' => 'sales.invoice.view'],
            // ⭐ বাতিল-ইনভয়েস — বিলিং-এর নিজের কাগজ (আন্তর্জাতিক মান, ৪ অক্টোবর ২০২৬); ইনভয়েস তালিকারই ছাঁকনি, আলাদা পাতা নয়
            ['label' => 'sales::menu.cancelled_invoices', 'cluster' => 'billing_documents', 'icon' => 'receipt', 'route' => 'sales.invoice.index',
                'route_params' => ['stage' => 'cancelled', 'cancelled' => 1], 'permission' => 'sales.invoice.view'],
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
            // ⓘ ৪ অক্টোবর ২০২৬: উপরের সারিতে আলাদা ট্যাব নয় — বিলিং ভাঁজে (SAP-এর "output monitor"-এর জায়গা)
            ['label' => 'sales::menu.print_queue', 'cluster' => 'billing_documents', 'icon' => 'printer', 'route' => 'sales.print_queue.index',
                'permission' => 'sales.invoice.view'],

            /*
             * ⭐ "DO তালিকা"-র জায়গায় "ডেলিভারি ট্র্যাকিং" — মালিক, ২ অক্টোবর ২০২৬: *"DO তালিকা bad diye er jaygay
             * ডেলিভারি ট্র্যাকিং hobe ekhanei sob bosbe"*। প্রতিটা বিক্রি কোথায় দাঁড়িয়ে, এক পাতায় ([[SaleTracking]])।
             */

            /*
             * ⭐ "আদায়" বোতাম নেই — মালিকের নির্দেশ, ১৯ সেপ্টেম্বর ২০২৬ (ক্রয়ের
             * "পরিশোধ"-এর মতোই): গ্রাহকের টাকা নেওয়া হিসাবের রসিদ ভাউচারের কাজ,
             * আর কাউন্টারের ডিপোজিটও এখন রসিদ ভাউচার। ⓘ পুরনো আদায়ের পাতা ও
             * রুট থাকল — ইতিহাস, চেক ফেরত আর বিলের পাতার লিংক ওখানেই খোলে।
             */
            ['label' => 'sales::menu.returns', 'icon' => 'refresh', 'route' => 'sales.return.index', 'permission' => 'sales.return.view'],

            /* ⓘ শিপমেন্ট এখন "ডিসপ্যাচ রেজিস্টার" নামে Delivery Processing ভাঁজে */

            /*
             * ⭐ মূল্য নির্ধারণ — শিপমেন্টের পরে, একটা ভাঁজে (মালিকের নির্দেশ, ২৮
             * সেপ্টেম্বর ২০২৬: *"age bosaw, code pore korbo"*)। ⓘ আপাতত
             * [[PlannedScreenController]]-এর সৎ পাতায় যায়।
             */
            // ⭐ আসল পাতা — মূল্য তালিকা, ২৭ সেপ্টেম্বর ২০২৬ (আগে 'তৈরি হচ্ছে')
            ['label' => 'sales::planned.pricing_lists', 'cluster' => 'pricing', 'icon' => 'wallet', 'route' => 'sales.price_list.index',
                'permission' => 'sales.order.view'],
            // ⭐ আসল পাতা — দর তালিকা: গ্রাহক, ধরন (চ্যানেল/ডিলার স্তর), এলাকা, আর সবার বিশেষ দাম (৫ অক্টোবর ২০২৬; আগে 'তৈরি হচ্ছে')
            ['label' => 'sales::planned.pricing_customer', 'cluster' => 'pricing', 'icon' => 'wallet', 'route' => 'sales.price_book.index',
                'route_params' => ['target' => 'customer'], 'permission' => 'sales.price_list.view'],
            ['label' => 'sales::planned.pricing_channel', 'cluster' => 'pricing', 'icon' => 'wallet', 'route' => 'sales.price_book.index',
                'route_params' => ['target' => 'tier'], 'permission' => 'sales.price_list.view'],
            ['label' => 'sales::planned.pricing_territory', 'cluster' => 'pricing', 'icon' => 'wallet', 'route' => 'sales.price_book.index',
                'route_params' => ['target' => 'territory'], 'permission' => 'sales.price_list.view'],
            ['label' => 'sales::planned.pricing_special', 'cluster' => 'pricing', 'icon' => 'wallet', 'route' => 'sales.price_book.index',
                'route_params' => ['target' => 'all'], 'permission' => 'sales.price_list.view'],
            ['label' => 'sales::planned.pricing_dynamic', 'cluster' => 'pricing', 'icon' => 'wallet', 'route' => 'sales.planned',
                'route_params' => ['screen' => 'pricing_dynamic'], 'permission' => 'sales.order.view'],

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
            // ⭐ কাগজের খাতা — রিপোর্ট সেন্টার ধাপ ৬ (মালিক, ১ অক্টোবর ২০২৬)
            ['label' => 'sales::register.title', 'icon' => 'list', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'register'], 'permission' => 'sales.report'],
            ['label' => 'sales::menu.pending_orders', 'icon' => 'clock', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'pending-orders'], 'permission' => 'sales.report'],
            // ⭐ আদায়ের সূচি — কার কাছে আজ যেতে হবে, সপ্তাহে কত আসার কথা (রিপোর্ট সেন্টার ধাপ ৪)
            ['label' => 'sales::due.title', 'icon' => 'calendar', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'collection-due'], 'permission' => 'sales.report'],
            // ⭐ পরিকল্পনা সংস্করণ ২ §৯ — খোলা আদেশ ও ব্যাক অর্ডার, সীমায় আটকানো আদেশ, বিক্রয় খাতা (৪ অক্টোবর ২০২৬)
            ['label' => 'sales::order_book.open_title', 'icon' => 'list', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'open-orders'], 'permission' => 'sales.report'],
            ['label' => 'sales::order_book.blocked_title', 'icon' => 'alert-triangle', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'credit-blocked'], 'permission' => 'sales.report'],
            ['label' => 'sales::order_book.invoice_title', 'icon' => 'book', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'invoice-book'], 'permission' => 'sales.report'],
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

            /* ⭐ মাসওয়ারি বিক্রয় — মালিক, ১ অক্টোবর ২০২৬ ([[MonthlySalesReport]]) */
            ['label' => 'sales::monthly.title', 'icon' => 'calendar', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'monthly'], 'permission' => 'sales.report'],
            ['label' => 'sales::margin.report_title', 'icon' => 'reports', 'route' => 'sales.margin.report.show',
                'route_params' => ['slug' => 'margin'], 'permission' => 'sales.margin.report'],
            ['label' => 'sales::return_reason.report_title', 'icon' => 'refresh', 'route' => 'sales.return.report.show',
                'route_params' => ['slug' => 'by-reason'], 'permission' => 'sales.return.report'],
            ['label' => 'sales::channel.report_title', 'icon' => 'share', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'by-channel'], 'permission' => 'sales.report'],
            ['label' => 'sales::route.report_title', 'icon' => 'reports', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'by-route'], 'permission' => 'sales.report'],
            /* ⭐ বিক্রয়কর্মী ধরে বিক্রি — পরিকল্পনা সংস্করণ ২ §৯ গ, ৬ অক্টোবর ২০২৬ ([[SalespersonReports]]) */
            ['label' => 'sales::salesperson_report.title', 'icon' => 'people', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'by-salesperson'], 'permission' => 'sales.report'],
            /* ⭐ আদেশ থেকে রওনার সময় — DO ধরে, পরিকল্পনা সংস্করণ ২ §৯ ঘ ([[DeliveryReports::ORDER_TO_DISPATCH]]) */
            ['label' => 'sales::order_dispatch.title', 'icon' => 'clock', 'route' => 'sales.report.show',
                'route_params' => ['slug' => 'order-to-dispatch'], 'permission' => 'sales.report'],

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
            // ⭐ ডিলারের মাসিক আদায়ের লক্ষ্য — বিলের "টার্গেট রিমাইন্ডার" (মালিক, ৩ অক্টোবর ২০২৬)
            ['label' => 'sales::customer_target.title', 'icon' => 'scale', 'route' => 'sales.customer_target.index',
                'permission' => 'sales.customer_target.view'],

            /* রুটের খাতা — লক্ষ্যমাত্রার পাশে: দুইটাই মাস ধরে দেখা হয় (NEXUS §২৭) */
            ['label' => 'sales::route.title', 'icon' => 'globe', 'route' => 'sales.route.index',
                'permission' => 'sales.route.view'],

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
        // ⭐ আদেশ বন্ধ — পুরো বিলের পরে, বা কারণসহ কম রেখে (মালিক, ৪ অক্টোবর ২০২৬: আন্তর্জাতিক মান)
        'sales.order.close',
        // ⭐ ডেলিভারি অর্ডার — লেখা আর দেখা (মালিকের বিক্রয়-ধারা, ২ অক্টোবর ২০২৬; সই আসে কোম্পানির ছক থেকে)
        'sales.do.view',
        'sales.do.create',
        /*
         * উদ্ধৃতি — পাঁচটা চাবি। ⚠️ রূপান্তর আলাদা, কারণ ওটা একটা আদেশ
         * জন্ম দেয় — আর নীতি আদেশ তৈরির চাবিও চায় ([[SalesQuotationPolicy]])।
         */
        'sales.quotation.view',
        'sales.quotation.create',
        'sales.quotation.update',
        'sales.quotation.cancel',
        'sales.quotation.convert',
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
        'sales.delivery.view',
        'sales.delivery.update',
        // ⓘ গেট পাস — দেখা/ছাপা আর বাতিল আলাদা: বাতিলে গেটের কাগজ অকেজো হয়
        'sales.gate_pass.view',
        'sales.gate_pass.cancel',

        /*
         * টার্গেট দেখা আর বসানো — দুইটা আলাদা চাবি।
         *
         * নিজের টার্গেট নিজে বদলাতে পারলে ওটা আর টার্গেট নয়, ইচ্ছা।
         */
        'sales.target.view',
        'sales.target.manage',
        // ⭐ ডিলারের মাসিক আদায়ের লক্ষ্য (৩ অক্টোবর ২০২৬)
        'sales.customer_target.view',
        'sales.customer_target.manage',

        /*
         * রুটের খাতা — দেখা আর ছক/লক্ষ্য বসানো আলাদা চাবি (NEXUS §২৭)।
         *
         * নিজের রুটের লক্ষ্য নিজে বদলাতে পারলে ওটা আর লক্ষ্য নয়, ইচ্ছা।
         * ⚠️ দেখার চাবিও আপাতত কেবল ম্যানেজারের ধাপে — রুটের পাতায় সব
         * ডিলারের বাকি, আর বিক্রয়কর্মী কেবল নিজের ডিলার দেখবেন (মালিক,
         * ২৬ সেপ্টেম্বর); সেই ছাঁকনি বাঁধনের কাজ।
         */
        'sales.route.view',
        'sales.route.manage',

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
        // ⭐ বাতিল-ইনভয়েস দেওয়ার চাবি — সাথে মালিকের সইয়ের ধারা (মালিক, ৪ অক্টোবর ২০২৬; [[SalesInvoiceCancellationService]])
        'sales.invoice.cancellation',
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

        /*
         * কারণ ধরে ফেরতের রিপোর্ট — NEXUS §২৪। `sales.report` থেকে আলাদা:
         * যাঁর ফেরত মাপা হচ্ছে, মাপকাঠিটা তাঁর হাতে থাকা চলে না।
         */
        'sales.return.report',
        'sales.pos',
        'sales.discount.override',
        'sales.report',
        // ⓘ মার্জিনের রিপোর্ট — খরচ দেখায়, তাই নিজের চাবি (NEXUS §৩২ · [[MarginReport]])
        'sales.margin.report',

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

        /*
         * লিড ও সুযোগ — দেখা (নিজেরটা) আর সবার-দেখা আলাদা।
         *
         * ⭐ মালিকের নিয়ম, ২৬ সেপ্টেম্বর ২০২৬: বিক্রয়কর্মী কেবল নিজেরটা।
         * ⛔ `manage` সবার লিড খোলে আর গ্রাহক বানায় — সিডারের ঢালাও
         * `sales.%` বাদ-তালিকাতেও আছে।
         */
        'sales.lead.view',
        'sales.lead.manage',
        'sales.opportunity.view',
        'sales.opportunity.manage',

        /*
         * ⭐ দর তালিকা — দেখা আর বসানো আলাদা (৫ অক্টোবর ২০২৬; [[PriceBookController]])।
         * ⛔ বসানো মানে কে কত দেবেন তা ঠিক করা — সিডারের ঢালাও `sales.%` বাদ-তালিকাতেও আছে।
         */
        'sales.price_list.view',
        'sales.price_list.manage',

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
        // ⓘ গুদামের লোক মাল তোলেন-বাঁধেন — ধাপ বদলানো তাঁর কাজ (NEXUS §২১)
        'Warehouse' => [
            'sales.delivery.view',
            'sales.delivery.update',
            'sales.gate_pass.view',
            // ⭐ ট্রিপ, লোডিং শিট, রওনা, settle, close — গুদামের কাজ (মালিকের ভূমিকা-ভাগ, ২৭ সেপ্টেম্বর;
            // হাঁটার স্ক্রিপ্টে ধরা, ২৯ সেপ্টেম্বর ২০২৬)। ⛔ বাতিল (`shipment.cancel`) নয় — ওটা তদারকির
            'sales.shipment.view',
            'sales.shipment.create',
        ],
        /*
         * কাউন্টার — প্রতিটা কোম্পানিতে ডিফল্টে। মালিক, ২৭ সেপ্টেম্বর ২০২৬: *"bosiye daw"*।
         * ⓘ কাউন্টারের কর্মী সব ডিলার দেখেন (মালিক, ২৬ সেপ্টেম্বর) — নাহলে বিল করবেন কীভাবে।
         */
        'Counter' => [
            'sales.delivery.view',
            'sales.challan.view',
            'sales.challan.create',
            'sales.invoice.view',
            'sales.invoice.create',
            'sales.collection.view',
            'sales.collection.create',
            'sales.return.view',
            'sales.return.create',
            'sales.pos',
        ],
        /*
         * ASM — বিক্রয়কর্মীর উপরের স্তর, প্রতিটা কোম্পানিতে ডিফল্টে (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।
         * ⚠️ SR→ASM→RSM→DSM-এর বাঁধন এখনো কোডে নেই; আপাতত কেবল চাবির তালিকা।
         */
        'ASM' => [
            'sales.quotation.view',
            'sales.quotation.create',
            'sales.quotation.update',
            'sales.quotation.cancel',
            'sales.quotation.convert',
            'sales.delivery.view',
            'sales.order.view',
            'sales.order.create',
            'sales.do.view', 'sales.do.create',
            'sales.challan.view',
            'sales.invoice.view',
            'sales.collection.view',
            'sales.report',
            'sales.target.view',
            'sales.commission.view',
        ],
        /*
         * RSM — ASM-এর উপরের স্তর, প্রতিটা কোম্পানিতে ডিফল্টে (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।
         * ⚠️ স্তরের বাঁধন এখনো কোডে নেই; আপাতত কেবল চাবির তালিকা।
         */
        'RSM' => [
            'sales.quotation.view',
            'sales.quotation.create',
            'sales.quotation.update',
            'sales.quotation.cancel',
            'sales.quotation.convert',
            'sales.delivery.view',
            'sales.order.view',
            'sales.order.create',
            'sales.do.view', 'sales.do.create',
            'sales.challan.view',
            'sales.invoice.view',
            'sales.collection.view',
            'sales.report',
            'sales.target.view',
            'sales.commission.view',
        ],
        /*
         * DSM — বিক্রয়ের সবচেয়ে উপরের স্তর, প্রতিটা কোম্পানিতে ডিফল্টে (মালিক, ২৭ সেপ্টেম্বর ২০২৬)।
         * ⚠️ স্তরের বাঁধন এখনো কোডে নেই; আপাতত কেবল চাবির তালিকা।
         */
        'DSM' => [
            'sales.quotation.view',
            'sales.quotation.create',
            'sales.quotation.update',
            'sales.quotation.cancel',
            'sales.quotation.convert',
            'sales.delivery.view',
            'sales.order.view',
            'sales.order.create',
            'sales.do.view', 'sales.do.create',
            'sales.challan.view',
            'sales.invoice.view',
            'sales.collection.view',
            'sales.report',
            'sales.target.view',
            'sales.commission.view',
        ],
        /*
         * ⭐ হিসাবরক্ষক — প্রতিটা কোম্পানিতে ডিফল্টে থাকে। মালিকের নির্দেশ, ২৭
         * সেপ্টেম্বর ২০২৬: *"Accountant role by defolt erp te create thakbe"*।
         * ⓘ জমার দাবির মঞ্জুরি কেবল তাঁর (মালিক, ২৬ সেপ্টেম্বর)।
         */
        'Accountant' => [
            'sales.invoice.view',
            'sales.collection.view',
            'sales.collection.create',
            'sales.claim.view',
            'sales.claim.decide',
        ],
        'Field Sales' => [
            'sales.quotation.view', 'sales.quotation.create', 'sales.quotation.update',
            'sales.order.view', 'sales.order.create', 'sales.do.view', 'sales.do.create',
            'sales.lead.view', 'sales.opportunity.view',
            'sales.collection.view', 'sales.collection.create',
        ],
        'Manager' => [
            // ⭐ মালিকের ভূমিকা-ভাগ, ২৭ সেপ্টেম্বর ২০২৬ (*"baki sob tumar poramorso motei koro"*)
            'sales.challan.create',
            'sales.invoice.create',
            // ⭐ ডেলিভারি নিশ্চিত ("পৌঁছেছে") — লাইভের যাচাইয়ে আটকেছিল (২৯ সেপ্টেম্বর ২০২৬)
            'sales.delivery.update',
            // ⭐ অর্ডার নিশ্চিত, আর ট্রিপ চালানো ও বাতিল — তদারকি (হাঁটার স্ক্রিপ্টে ধরা, ২৯ সেপ্টেম্বর ২০২৬)।
            // ⚠️ `order.update` খসড়া সম্পাদনাও খোলে — নিশ্চিত আর সম্পাদনা একই চাবিতে বাঁধা (মালিককে জানানো)
            'sales.order.update',
            // ⭐ আদেশ বন্ধ — নিশ্চিতের মতোই তদারকির কাজ (৪ অক্টোবর ২০২৬)। ⚠️ কেবল নতুন কোম্পানিতে পৌঁছায় — ভূমিকার ছক চলতি ভূমিকা বাড়ায় না
            'sales.order.close',
            'sales.shipment.create',
            'sales.shipment.cancel',
            'sales.quotation.view',
            'sales.delivery.view',
            'sales.lead.view', 'sales.lead.manage',
            'sales.opportunity.view', 'sales.opportunity.manage',
            'sales.route.view',
            'sales.order.view', 'sales.challan.view', 'sales.invoice.view',
            'sales.collection.view', 'sales.return.view', 'sales.shipment.view',
            'sales.report',
            'sales.return.report',
            // ⓘ দর তালিকা দেখা — তদারকি; বসানো নয় (মালিকের কাজ)
            'sales.price_list.view',
        ],
    ],

    'doc_types' => [
        'SO' => 'sales::doc.order',
        // ⭐ একটা বিক্রির একটাই নম্বর — DO বা সরাসরি বিক্রিতে জন্ম (মালিক, ২৯ সেপ্টেম্বর ২০২৬; [[SaleNumber]])
        'S' => 'sales::doc.sale',
        // ⭐ খসড়ার নিজের ক্রম — আসল INV/CHA নম্বর কেবল নিশ্চিতে, সরকারি ক্রমে ফাঁক নয় (মালিক, ২ অক্টোবর ২০২৬)
        'DRF' => 'sales::doc.draft',
        'QTN' => 'sales::quotation.doc',
        'DC' => 'sales::doc.challan',
        // ⭐ গেট পাস — রওনার মুহূর্তে নিজে জন্মায় ([[GatePassService]]); সিরিজ কোম্পানি-প্রতি
        'GP' => 'sales::doc.gate_pass',
        // ⭐ কাউন্টারের ডেলিভারি অর্ডার — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: আলাদা উপসর্গ (DS),
        // খসড়া আর নিশ্চিত একই সারিতে; উপসর্গটা কন্ট্রোল প্যানেলের নম্বর-সারি থেকে বদলানো যায়
        'DS' => 'sales::doc.counter_do',
        // ⭐ ডেলিভারি অর্ডার — নিজের কাগজ, নিজের ক্রম (মালিকের বিক্রয়-ধারা, ২ অক্টোবর ২০২৬; [[DeliveryOrder]])
        'DO' => 'sales::doc.delivery_order',
        // ⭐ ডেলিভারি অর্ডার — নিজের কাগজ, নিজের ক্রম (মালিকের বিক্রয়-ধারা, ২ অক্টোবর ২০২৬; [[DeliveryOrder]])
        'DO' => 'sales::doc.delivery_order',
        'TRP' => 'sales::doc.shipment',
        'INV' => 'sales::doc.invoice',
        // ⭐ বাতিল-ইনভয়েস — নিজের ক্রম, CXL-0001 (মালিক, ৪ অক্টোবর ২০২৬); নম্বরের পর্দা থেকে বদলানো যায়
        'CXL' => 'sales::cancellation.doc',
        'COL' => 'sales::doc.collection',
        'SR' => 'sales::doc.return',
        'CMC' => 'sales::doc.commission',
        'LD' => 'sales::crm.doc_lead',
        'OPP' => 'sales::crm.doc_opportunity',
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

    /*
     * সুযোগের ধাপ — একই নামে দুইটা নয় ([[MasterListService::create()]] পাহারা ডাকে)।
     */
    'duplicates' => [
        ['model' => OpportunityStage::class, 'name' => ['name_en', 'name_bn']],
    ],

    'drill_sources' => [
        'sales_invoice_cancellation' => \App\Modules\Sales\Models\SalesInvoiceCancellation::class,
        'commission_claim' => CommissionClaim::class,
        'sales_order' => SalesOrder::class,
        'sales_quotation' => SalesQuotation::class,
        'delivery_challan' => DeliveryChallan::class,
        'shipment' => Shipment::class,
        'sales_invoice' => SalesInvoice::class,
        'collection' => Collection::class,
        'sales_return' => SalesReturn::class,
    ],

    // ডিলারের মাসিক লক্ষ্য একসাথে অনেকের (৩ অক্টোবর ২০২৬; [[CustomerTargetImporter]])
    'imports' => [
        'customer_target' => \App\Modules\Sales\Imports\CustomerTargetImporter::class,
    ],

    /*
     * ⭐ রিপোর্টের সাধারণ ছাঁকনি — বিক্রয়কর্মী বিক্রয়ের, কোরের নয় (সমন্বয়কের শর্ত ক) ([[ReportFilters]], রিপোর্ট সেন্টার ধাপ ১)।
     * ⓘ পর্দা বাছাই-ঘর আঁকে, আর ইঞ্জিন ঠিকানার মান এই তালিকা দিয়ে মেলায় — বাইরের নম্বর এলে রিপোর্টই ফেরে।
     */
    'report_filters' => [
        'salesman_id' => \App\Modules\Sales\Reports\Filters\SalesmanFilter::class,
    ],

    'reports' => [
        SalesReports::class,
        // ⭐ কাগজের খাতা — রিপোর্ট সেন্টার ধাপ ৬, বিক্রয়ের অর্ধেক
        \App\Modules\Sales\Reports\SalesRegisterReports::class,
        \App\Modules\Sales\Reports\MarginReport::class,
        \App\Modules\Sales\Reports\SalesReturnReasonReports::class,
        SalesChannelReports::class,
        RouteReports::class,
        // ⭐ খোলা আদেশ ও ব্যাক অর্ডার, সীমায় আটকানো আদেশ, বিক্রয় খাতা — পরিকল্পনা সংস্করণ ২ §৯ (৪ অক্টোবর ২০২৬)
        \App\Modules\Sales\Reports\SalesOrderBookReports::class,
        // ⭐ বাকি ও আদায় — সীমার ব্যবহার, বাকি বন্ধ, ঝুঁকির গ্রাহক, সীমা বদলের ইতিহাস (৫ অক্টোবর ২০২৬)
        \App\Modules\Sales\Reports\CreditControlReports::class,
        // ⭐ ডেলিভারির রিপোর্ট — OTIF আদেশের লাইন ধরে (পরিকল্পনা সংস্করণ ২ §৯, ৬ অক্টোবর ২০২৬)
        \App\Modules\Sales\Reports\DeliveryReports::class,
        // ⭐ বিক্রয়কর্মী ধরে বিক্রি (পরিকল্পনা সংস্করণ ২ §৯ গ)
        \App\Modules\Sales\Reports\SalespersonReports::class,
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
        // ⭐ আদায় পাকা — টাকার জন্য আটকে থাকা DO আবার যাচাই (বিক্রয়ের কাজের ধারা, ২ অক্টোবর ২০২৬)
        \App\Modules\Sales\Events\CollectionConfirmed::class,
        // ⭐ ডেলিভারি অর্ডার — সুপারভাইজার পেরোল / থামল (২ অক্টোবর ২০২৬); শোনেন abos-86 (হিসাব, মজুদ)
        \App\Modules\Sales\Events\DeliveryOrderSupervisorApproved::class,
        \App\Modules\Sales\Events\DeliveryOrderCancelled::class,
        // ⭐ ডেলিভারি অর্ডার — সুপারভাইজার পেরোল / থামল (২ অক্টোবর ২০২৬); শোনেন abos-86 (হিসাব, মজুদ)
        \App\Modules\Sales\Events\DeliveryOrderSupervisorApproved::class,
        \App\Modules\Sales\Events\DeliveryOrderCancelled::class,
        // ⭐ বিক্রয় আদেশ বাতিল / বন্ধ (৪ অক্টোবর ২০২৬); শোনেন abos-86 (নতুন ধারার হোল্ড ছাড়া)
        \App\Modules\Sales\Events\SalesOrderCancelled::class,
        \App\Modules\Sales\Events\SalesOrderClosed::class,
        // ⭐ বিক্রয় আদেশের শেষ সই (নতুন ধারা); শোনেন abos-86 (হোল্ড, তারপর `confirmed`)
        \App\Modules\Sales\Events\SalesOrderApproved::class,
        // ⭐ এক লাইনের বাকিটা "আর দেওয়া হবে না" (নকশার ধাপ ৭); শোনেন abos-86 (নতুন ধারার হোল্ড ছোট করা)
        \App\Modules\Sales\Events\SalesOrderLineRejected::class,
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
    /*
     * ⭐ শেষ সইয়ের পরে কাউন্টারের আটকে থাকা বিক্রি নিজে শেষ — মালিকের
     * সিদ্ধান্ত ১, ২৭ সেপ্টেম্বর ২০২৬ ([[HeldCounterSaleFinisher]])।
     */
    'listeners' => [
        /*
         * ⭐ ডেলিভারি অর্ডারের হিসাব আর মাল — বিক্রয়ের কাজের ধারা, ধাপ গ + ঘ (৩ অক্টোবর ২০২৬)।
         * সুপারভাইজারের শেষ অনুমোদনে মাল আটকানো আর হিসাবের যাচাই; বাতিলে ছাড়; টাকা এলে আটকে থাকা DO আবার।
         */
        \App\Modules\Sales\Events\DeliveryOrderSupervisorApproved::class => [\App\Modules\Sales\Listeners\HoldAndCheckTheDeliveryOrder::class],
        \App\Modules\Sales\Events\DeliveryOrderCancelled::class => [\App\Modules\Sales\Listeners\ReleaseTheDeliveryOrderStock::class],
        // ⭐ অনুমোদিত বিক্রয় আদেশ — মাল ধরা, তারপর নিশ্চিত (SO+DO মেশানো, ধাপ ৪, ৪ অক্টোবর ২০২৬; [[HoldTheStockForTheApprovedOrder]])
        \App\Modules\Sales\Events\SalesOrderApproved::class => [\App\Modules\Sales\Listeners\HoldTheStockForTheApprovedOrder::class],
        \App\Modules\Sales\Events\CollectionConfirmed::class => [\App\Modules\Sales\Listeners\RecheckTheHeldDeliveryOrders::class],
        \App\Modules\Accounts\Events\VoucherPosted::class => [\App\Modules\Sales\Listeners\RecheckTheHeldDeliveryOrders::class],
        \App\Modules\Accounts\Events\ChequeCleared::class => [\App\Modules\Sales\Listeners\RecheckTheHeldDeliveryOrders::class],
        \App\Core\Events\ApprovalDecided::class => [
            \App\Modules\Sales\Listeners\FinishTheHeldSaleOnTheLastSignature::class,
            // ⭐ অফিসের চালানও — শেষ সইয়ে নিজে পাকা ([[SignedChallanConfirmer]], ২৯ সেপ্টেম্বর ২০২৬)
            \App\Modules\Sales\Listeners\ConfirmTheChallanOnTheLastSignature::class,
            // ⭐ DO-র সুপারভাইজার — শেষ সইয়ে অনুমোদিত, ফেরতে থামে ([[MoveTheDeliveryOrderOnItsSignature]], ২ অক্টোবর ২০২৬)
            \App\Modules\Sales\Listeners\MoveTheDeliveryOrderOnItsSignature::class,
            // ⭐ বিক্রয় আদেশের সুপারভাইজার — নতুন ধারা ([[MoveTheOrderOnItsSignature]], ৪ অক্টোবর ২০২৬)
            \App\Modules\Sales\Listeners\MoveTheOrderOnItsSignature::class,
            // ⭐ বাতিল-ইনভয়েস — শেষ সইয়ে পাকা, প্রত্যাখ্যানে বাতিল (৪ অক্টোবর ২০২৬)
            \App\Modules\Sales\Listeners\FinishTheCancellationOnTheLastSignature::class,
            // ⭐ ফেরত — শেষ সইয়ে পাকা, প্রত্যাখ্যানে বাতিল (বিক্রয় পরিকল্পনা §৬, ৬ অক্টোবর ২০২৬)
            \App\Modules\Sales\Listeners\FinishTheReturnOnTheLastSignature::class,
        ],
    ],

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
        'margin' => 'sales::margin.approval',
        'order' => 'sales::approval.order',
        'quotation' => 'sales::quotation.approval',
        'challan' => 'sales::approval.challan',
        'collection' => 'sales::approval.collection',
        'discount' => 'sales::approval.discount',
        // ⭐ বাতিল-ইনভয়েস — সইয়ের ছক প্রতিষ্ঠানের, ঐচ্ছিক (৪ অক্টোবর ২০২৬; [[SalesInvoiceCancellationService]])
        'cancellation' => 'sales::cancellation.approval',
        // ⭐ DO-র সুপারভাইজার — কোম্পানির নিজের ছক, ১–৩ স্তর (মালিকের বিক্রয়-ধারা, ২ অক্টোবর ২০২৬)
        'delivery_order' => 'sales::delivery_order.approval',
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
            'per_branch' => true,
            'label' => 'sales::settings.paper_invoice',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        /*
         * ⭐ বিলের নকশা — "ক্লাসিক টেবিল ইনভয়েস", মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬।
         *
         * ⓘ চলতি নকশা (`standard`) যেমন ছিল তেমনই থাকে, আর বাছা যায়। ⭐ ২৯ সেপ্টেম্বর থেকে
         * ডিফল্ট ক্লাসিক (মালিক: "by defolt kore daw") — সেটিং না বসানো কোম্পানি ক্লাসিক পায়। ছাঁচ
         * ([[sales::print.invoice-classic]]): মাথায় তিন কলাম (কাকে · কোন
         * গাড়িতে · কোন বিল), নিচে টাকার সারি আর আদায়ের ছক পাশাপাশি।
         *
         * ⛔ থার্মালে খাটে না: তিন কলামের মাথা ৮০মিমিতে ধরে না, তাই রোলে
         * সবসময় চলতি রসিদ ([[SalesPrintController::invoice()]])।
         */
        [
            'key' => 'sales.print.design.invoice',
            'label' => 'sales::settings.design_invoice',
            'type' => 'choice',
            /* ⓘ তালিকা একটাই — [[InvoiceDesigns]]; নতুন নকশা সেখানে এক সারি */
            'options' => InvoiceDesigns::options(),
            'option_label' => 'sales::settings.design.',
            'per_branch' => true,

            /* ⭐ ছাপার নিয়ন্ত্রণের "বিল → A4" ট্যাবে এই নকশাগুলোর কার্ড ([[PrintControlController::designsFor()]]) */
            'print_designs' => ['paper' => 'invoice', 'size' => 'a4', 'sample_route' => 'sales.invoice_sample'],

            /* ⭐ ৩০ সেপ্টেম্বর ২০২৬: মালিকের নতুন ডিফল্ট "মোনো ক্লাসিক হালকা" ([[PaperDesigns::defaultFor()]]) */
            'default' => PaperDesigns::defaultFor('invoice', 'a4'),
            'group' => 'print',
        ],
        /*
         * ⭐ বাকি প্রতিটা কাগজ-মাপের নিজের নকশা — মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"printe template sob gulo
         * kore deploy diba A4 A5 tharmal tintiroi"*। ⓘ বিকল্পগুলো ফাইল থেকে ([[PaperDesigns::codes()]]),
         * তাই abos-3c নতুন ছাঁচ রাখলে কার্ড নিজেই আসে। ডিফল্ট নতুন নকশা থেকে, মালিকের "OK"-তে
         * ([[PaperDesigns::defaultFor()]]); `standard` = চলতি কাগজ, বাছা যায়। গ্রুপ `print_paper`।
         */
        ...array_merge(...array_map(fn (string $paper) => array_values(array_filter(array_map(
            fn (string $size) => $paper === 'invoice' && $size === 'a4' ? null : [
                'key' => PaperDesigns::key($paper, $size),
                'label' => 'sales::settings.design_invoice',
                'type' => 'choice',
                'options' => ['standard', ...PaperDesigns::codes($paper, $size)],
                'option_label' => 'sales::settings.design.',
                'per_branch' => true,
                'default' => PaperDesigns::defaultFor($paper, $size),
                'group' => 'print_paper',
                'print_designs' => array_filter([
                    'paper' => $paper,
                    'size' => $size,
                    /* ⓘ নমুনার পাতা কাগজ ধরে; রুট না থাকলে কার্ডে ছবি নেই, ভাঙে না ([[PrintControlController::designsFor()]]) */
                    'sample_route' => "sales.{$paper}_sample",
                ]),
            ],
            PaperDesigns::SIZES,
        ))), PaperDesigns::PAPERS)),
        /*
         * ⭐ "Set Invoice Information" — মালিকের নির্দেশ, ২৯ সেপ্টেম্বর ২০২৬।
         *
         * *"Set Invoice Information ei name alada tab koro"*, তারপর *"Print control er
         * vitotre korte paro"*। ⓘ তাই গ্রুপ `invoice_info`: সাধারণ সেটিংস পর্দা এগুলো
         * আঁকে না, আঁকে ছাপার নিয়ন্ত্রণের ভেতরের ঐ ভাগটা ([[InvoiceInfoController]]) —
         * আর সে সেটিংয়ের নাম জানে না, কেবল এই ঘোষণা পড়ে (`part` দিয়ে ভাগ করে)।
         *
         * ── ⚠️ মাথার ঘরগুলো বদল, নকল নয় ──────────────────────────────────
         * ⓘ খালি = কোম্পানির প্রোফাইলের মান ([[InvoicePrintLook::header()]])। ⛔ প্রোফাইলের
         * নাম-ফোন এখানে নকল করলে প্রোফাইল বদলালেও বিলে পুরনোটা ছাপা হত, নীরবে।
         *
         * ⓘ এগুলো কেবল ক্লাসিক নকশার; চলতি নকশার অংশ-কলাম ঐ পাতারই উপরের ভাগে।
         */
        ...array_map(fn (string $field) => [
            'key' => "sales.print.header.{$field}",
            'label' => "sales::settings.invoice_info.header.{$field}",
            'type' => 'string',
            'default' => null,
            'group' => 'invoice_info',
            'per_branch' => true,
            'part' => 'header',
        ], ['name', 'address', 'phone', 'email', 'website']),

        /*
         * ⓘ দেখানো/লুকানোর সুইচ — সবগুলো ডিফল্টে চালু, অর্থাৎ আজকের কাগজ যেমন আছে তেমন।
         *
         * ⚠️ DUPLICATE বন্ধ করা যায় (মালিকের তালিকায় আছে), কিন্তু ছাপার সারিতে ওঠা বন্ধ হয় না —
         * কতবার ছাপা হলো তা [[PrintJob]]-এ থাকেই; কেবল কাগজের ছাপটা যায়।
         */
        ...array_map(fn (string $what) => [
            'key' => "sales.print.show.{$what}",
            'label' => "sales::settings.invoice_info.show.{$what}",
            'type' => 'boolean',
            // ⭐ পণ্যের কোড আর লট ডিফল্টে বন্ধ — মালিক, ৩ অক্টোবর ২০২৬: *"print e product id dewar dorkar nai"*; ৪ অক্টোবর:
            // *"invoice challan print e lot & product code /id print er dorkar nai"*। ⓘ চাইলে কন্ট্রোল প্যানেলে চালু করা যায়।
            'default' => ! in_array($what, ['product_code', 'lot'], true),
            'group' => 'invoice_info',
            'per_branch' => true,
            'part' => 'show',
        ], ['bin', 'invoice_type', 'duplicate', 'order_no', 'transport', 'free', 'total_qty',
            'grand_total_row', 'previous_due', 'amount_words', 'deposits', 'qr', 'product_code', 'lot']),

        /*
         * ⭐ চালানে কী ছাপা হবে — আলাদা সারি (মালিক, ৩ অক্টোবর ২০২৬; [[InvoicePrintLook::challanShows()]])।
         * ⓘ `prices` = চালানের ডিফল্ট — টাকাসহ না টাকা ছাড়া; ছাপার বোতামে প্রতিবার বদলানো যায়।
         * ⓘ পণ্যের কোড ডিফল্টে বন্ধ, বাকি সব চালু — আজকের চালান যেমন ছিল।
         */
        ...array_map(fn (string $what) => [
            'key' => "sales.print.challan_show.{$what}",
            'label' => "sales::settings.invoice_info.challan_show.{$what}",
            'type' => 'boolean',
            'default' => ! in_array($what, ['product_code', 'lot'], true), // ⭐ কোড আর লট বন্ধ — মালিক, ৪ অক্টোবর ২০২৬
            'group' => 'invoice_info',
            'per_branch' => true,
            'part' => 'challan_show',
        ], ['product_code', 'lot', 'free', 'total_qty', 'qr', 'transport', 'order_no', 'prices']),

        /*
         * ⓘ সইয়ের ঘর — কয়টা (২–৪), আর প্রতিটার নাম। খালি নাম = নমুনার বাংলা নাম
         * (গ্রহণকারী/পরিবহক · প্রস্তুতকারী · অনুমোদনকারী); চতুর্থটার নিজের নাম নেই, খালি থাকলে
         * ঘরটা নামহীন দাগ না হয়ে বাদ পড়ে ([[InvoicePrintLook::signatures()]])।
         */
        [
            'key' => 'sales.print.signature_count',
            'label' => 'sales::settings.invoice_info.signature_count',
            'type' => 'choice',
            'options' => ['2', '3', '4'],
            'default' => '3',
            'group' => 'invoice_info',
            'per_branch' => true,
            'part' => 'signature',
        ],
        ...array_map(fn (int $n) => [
            'key' => "sales.print.signature.{$n}",
            'label' => "sales::settings.invoice_info.signature_label",
            'label_n' => $n,
            'type' => 'string',
            'default' => null,
            'group' => 'invoice_info',
            'per_branch' => true,
            'part' => 'signature',
        ], [1, 2, 3, 4]),

        /*
         * ⓘ ক্লাসিক বিলের নিচের লাল বাক্য — প্রতিটা ব্যবসার নিজের কথা।
         * ⚠️ খালি রাখলে ভাষার ফাইলের বাক্যটা বসে; ABOS অনেক ব্যবসায় চলে,
         * তাই এক ব্যবসার শর্ত কোডে বাঁধা হয়নি। ⭐ ২৯ সেপ্টেম্বর থেকে "Set Invoice
         * Information"-এ (গ্রুপ `invoice_info`) — বিলের সব লেখা এক জায়গায়।
         */
        [
            'key' => 'sales.print.invoice_footnote',
            'label' => 'sales::settings.invoice_footnote',
            'type' => 'string',
            'default' => null,
            'group' => 'invoice_info',
            'per_branch' => true,
            'part' => 'note',
            // ⓘ ঘর খালি হলে এই লেখাই ভরা থাকে, যাতে ব্যবহারকারী বদলে নিতে পারেন (মালিক, ৩ অক্টোবর ২০২৬)
            'default_text' => 'sales::print.classic.footnote',
        ],
        [
            // ⭐ সরু রোলের ছোট নির্দেশনা — মালিক, ৩ অক্টোবর ২০২৬: "dui kagoje dui rokom"
            'key' => 'sales.print.invoice_footnote_thermal',
            'label' => 'sales::settings.invoice_footnote_thermal',
            'type' => 'string',
            'default' => null,
            'group' => 'invoice_info',
            'per_branch' => true,
            'part' => 'note',
            'default_text' => 'sales::print.classic.footnote_thermal',
        ],
        [
            /*
             * ⭐ বিলের ACCOUNT MOVEMENT-এ কয়টা লেনদেন — মালিক, ৩ অক্টোবর ২০২৬: *"koyta ba ki ki tranjecton print hobe
             * mane koyta line print hobe"*। ০ = বিলের মাসের সব; ১–৫০ = মাসের শেষ এতগুলো (শুরুর জের তখন তার আগের যোগ)।
             */
            'key' => 'sales.print.movement_lines',
            'label' => 'sales::settings.movement_lines',
            'type' => 'integer',
            'default' => 0,
            'group' => 'invoice_info',
            'per_branch' => true,
            'part' => 'show',
        ],
        [
            // ⭐ গেট পাসের কাগজ — মালিক: আধা পাতা (A5), ২৮ সেপ্টেম্বর ২০২৬ ([[SalesPrintController::gatePassDocument()]])
            'key' => 'sales.print.paper.gate_pass',
            'per_branch' => true,
            'label' => 'sales::settings.paper_gate_pass',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A5,
            'group' => 'print',
        ],
        [
            'key' => 'sales.print.paper.challan',
            'per_branch' => true,
            'label' => 'sales::settings.paper_challan',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        [
            'key' => 'sales.print.paper.order',
            'per_branch' => true,
            'label' => 'sales::settings.paper_order',
            'type' => 'choice',
            'options' => PaperSize::all(),
            'default' => PaperSize::A4,
            'group' => 'print',
        ],
        [
            'key' => 'sales.print.paper.receipt',
            'per_branch' => true,
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
        /*
         * ⭐ ডেলিভারি অর্ডারের মাল কতক্ষণ — মালিক, ৩ অক্টোবর ২০২৬: *"24h er jonno korakori atkabe, baki 2din
         * dekhabe but bikroy cholbe"* ([[DeliveryOrderStock]])। সুপারভাইজারের অনুমোদন থেকে গোনা।
         */
        [
            'key' => 'sales.do_hard_hold_hours',
            'label' => 'sales::settings.do_hard_hold_hours',
            'type' => 'integer',
            'default' => 24,
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
            /*
             * ⭐ ডিফল্ট বন্ধ — মালিক, ৬ অক্টোবর ২০২৬: *"না, ডিপোতে কনফার্ম করার পরই, মানে ডেলিভারি চালান থেকে স্টক ব্লক হবে"*।
             * ⓘ বন্ধ থাকলে আদেশ (পুরনো বা নতুন ধারা) কিছুই ধরে না — পাতায় কেবল কত আছে আর কত কম (ATP); মাল আটকায় চালান
             * নিশ্চিত হলে। আদেশের কোনো সময়সীমাও নেই। চালু করলে আগের মতো আদেশ নিশ্চিত/অনুমোদিত হলেই ধরা।
             */
            'key' => 'sales.reserve_on_order',
            'label' => 'sales::settings.reserve_on_order',
            'type' => 'boolean',
            'default' => false,
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
             * ⭐ হাতের ছাড়ের সীমা — সারিতে আর পুরো বিলে, শতাংশে (মালিক, ৫ অক্টোবর ২০২৬: আন্তর্জাতিক মান; [[DiscountCap]])।
             * ⓘ ০ = সীমা নেই (ডিফল্ট, আজকের আচরণ)। ⛔ উপরে গেলে বিল ফেরে; ভিতরে থাকলেও মালিকের সই আগের মতো লাগে।
             */
            'key' => 'sales.discount_cap_line_percent',
            'label' => 'sales::settings.discount_cap_line_percent',
            'type' => 'number',
            'default' => 0,
            'group' => 'limits',
        ],
        [
            'key' => 'sales.discount_cap_bill_percent',
            'label' => 'sales::settings.discount_cap_bill_percent',
            'type' => 'number',
            'default' => 0,
            'group' => 'limits',
        ],
        [
            /*
             * বিক্রিতে ভ্যাট — মালিক, ২৮ সেপ্টেম্বর ২০২৬ (রাত): দুই সুইচ, ডিফল্টে সব জায়গায় বন্ধ।
             * ⓘ বন্ধ মানে বন্ধ: ঘর নেই, সার্ভার ভ্যাট নেয় না ([[CalculatesSalesLines::lineFigures()]])।
             * ক্রয়ের সুইচ আলাদা (`purchase.vat_enabled`) — কোনো দিক অন্যটা পড়ে না।
             */
            'key' => 'sales.vat_enabled',
            'label' => 'sales::settings.vat_enabled',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            /*
             * ⭐ মার্জিনের সীমা — NEXUS §৩২ ([[MarginGuard]])। ⓘ ০ মানে খরচের নিচে বিক্রি ধরা পড়ে,
             * খরচে বা উপরে নয়। কোম্পানি-প্রতি, কারণ ABOS অনেক ব্যবসায় চলে।
             */
            'key' => 'sales.margin.floor_percent',
            'label' => 'sales::margin.setting_floor',
            'type' => 'number',
            'default' => 0,
            'group' => 'limits',
        ],
        [
            // ⓘ সীমার নিচে হলে কী — সতর্ক (ডিফল্ট, আজকের মতো বিক্রি চলে), অনুমোদন, না আটকানো
            'key' => 'sales.margin.action',
            'label' => 'sales::margin.setting_action',
            'type' => 'choice',
            'options' => ['warn', 'approval', 'block'],
            'option_label' => 'sales::margin.action_',
            'default' => 'warn',
            'group' => 'limits',
        ],
        [
            /*
             * ⭐ ফ্রি কেনার লটের অনুপাতে বাঁধা কি না — মালিক, ৪ অক্টোবর ২০২৬ (সংস্করণ ২)।
             * ⓘ চালু (ডিফল্ট, আজকের মতো): লটে যে অনুপাতে ফ্রি এসেছিল তার বেশি নয় ([[FreeRatio]])।
             * বন্ধ (আন্তর্জাতিক মান): ফ্রি আসে স্কিম থেকে, ফ্রি-ভাণ্ডারে যতটা আছে ততটা। মালিক চালুর দিন বন্ধ করবেন।
             */
            'key' => 'sales.free_by_lot_ratio',
            'label' => 'sales::settings.free_by_lot_ratio',
            'type' => 'boolean',
            'default' => true,
            'group' => 'limits',
        ],
        [
            /*
             * ⭐ ফ্রি-ভাণ্ডারের বাইরেও ফ্রি — মালিক, ৪ অক্টোবর ২০২৬: *"free dewal ta tule daw othoba control panel e switch daw.
             * lote free thakle auto bosbe, na thakle free dite parbe"*। ⓘ চালু: ফ্রি-ভাণ্ডারে যতটা আছে ততটা সেখান থেকে, বাকিটা ঐ লটের
             * নিজের মাল থেকে — খাতায় প্রচারের খরচ (৫২২২), আয় নয় (IFRS ১৫)। লটের অনুপাতের দেয়ালও তখন থামায় না।
             * বন্ধ (ডিফল্ট): আজকের দেয়াল, হুবহু।
             */
            'key' => 'sales.free_beyond_pool',
            'label' => 'sales::settings.free_beyond_pool',
            'type' => 'boolean',
            'default' => false,
            'group' => 'limits',
        ],
        [
            /*
             * ⭐ গেট পাসে মাল বেরোনো আর ইনভয়েস — মালিক, ৪ অক্টোবর ২০২৬ ("অবশ্যই ইন্টারন্যাশনাল স্ট্যান্ডার্ড"; SAP-এর Post Goods
             * Issue, IFRS ১৫)। ⓘ চালু: গাড়িতে যাওয়া বিক্রির চালান নিশ্চিতে মাল কেবল আটকায় (মজুদ কমে না), বিল খসড়ায় চালানে বাঁধা
             * থাকে; গেট পাসে মাল বেরোয়, খরচ ওঠে, বিল একই নম্বরে পাকা হয় ([[GoodsIssue]])। "এখনই নিয়ে যাবেন" এক চাপে সব।
             * বন্ধ (ডিফল্ট): আজকের আচরণ, হুবহু। ⚠️ সুইচ বদলালে পুরনো কাগজ নিজের নিয়মেই থাকে (`sal_challans.issue_at_gate`)।
             */
            'key' => 'sales.invoice_at_goods_issue',
            'label' => 'sales::settings.invoice_at_goods_issue',
            'type' => 'boolean',
            'default' => false,
            'group' => 'limits',
        ],
        [
            /*
             * ⭐ একই বিল দুইবার নয় — মালিক, ৫ অক্টোবর ২০২৬: *"এভাবে ডাবল যাতে না হয় সেই ব্যবস্থা করো"* (INV-0006 → DRF-0012)।
             * ⓘ একই ক্রেতার কাউন্টারের বিল, এত মিনিটের মধ্যে, হুবহু একই সারি আর মোট — থামে, আগের নম্বর বলে; "আবার করুন" টিকে
             * যায়, অডিটে ([[DirectSaleService::refuseARepeatBill()]])। ০ = বন্ধ।
             */
            'key' => 'sales.duplicate_bill_minutes',
            'label' => 'sales::repeat_bill.setting',
            'type' => 'integer',
            'default' => 30,
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
        /*
         * ⭐ DO বিক্রয় আদেশে মেশানো — কোম্পানির সুইচ (মালিক, ৪ অক্টোবর ২০২৬; নকশার §৫)।
         *
         * ⓘ চালু হলে আদেশ নতুন ধারায়: জমা → বাকির যাচাই → সুপারভাইজার → অনুমোদিত ([[SalesOrderService::submit()]])।
         * ⚠️ ডিফল্ট বন্ধ — বন্ধ থাকলে সব আজকের মতো। চালু হবে কেবল নকশার ধাপ ১৩-এর কমান্ডে, আগে ডেমোতে।
         */
        [
            'key' => 'sales.orders_replace_do',
            'label' => 'sales::order_status.setting_replace_do',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
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
            'key' => 'sales.screen_quotations',
            'label' => 'sales::quotation.settings.screen',
            'type' => 'boolean',
            'default' => true,
            'group' => 'screens',
            'holds' => SalesQuotation::class,
        ],
        [
            // ⓘ ফর্মে মেয়াদ না দিলে কত দিন — এক ডিপোর দর সপ্তাহে বদলায়, আরেকটার মাসে
            'key' => 'sales.quotation_valid_days',
            'label' => 'sales::quotation.settings.valid_days',
            'type' => 'integer',
            'default' => 15,
            'group' => 'entry',
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
