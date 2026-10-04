<?php

declare(strict_types=1);

/**
 * Trade Promotion & Incentive Management — মডিউলের পরিচয়পত্র।
 *
 * ── ⭐ মালিকের স্পেক ও সিদ্ধান্ত, ২৫ সেপ্টেম্বর ২০২৬ ─────────────────
 * *"eta alada module hobe"* · *"100% plan onuzayi korbe"*।
 *
 * ── ⚠️ কেন এটা Sales-এর ভিতরে নয় ───────────────────────────────────
 * ⓘ স্পেকের ১ নম্বর ধারা: অফার একদিন কেবল বিক্রয়ের জিনিস থাকবে না —
 * পরিবেশকের স্কিম, বিক্রয়কর্মীর ইনসেনটিভ, ক্রেতার লয়্যালটি, বিপণনের
 * ক্যাম্পেইন, অর্থের খরচ আর মজুদের উপহার — সব একটাই ইঞ্জিনে।
 *
 * ⛔ আর ২২ নম্বর ধারা সরাসরি নিষেধ করে: হিসাবটা কখনো Sales Invoice,
 * Sales Order বা Direct Sales পর্দার ভিতরে লেখা যাবে না।
 *
 * ── ⓘ তাহলে ইঞ্জিনটা কোরে কেন নয় ───────────────────────────────────
 * ⚠️ কোর কোনো মডিউলের নাম জানে না (`BoundariesTest`, §১৯.৭), অথচ এই
 * ইঞ্জিনকে পণ্য, ক্রেতা ও মজুদ চিনতে হয়। ⭐ তাই ইঞ্জিনটা এই মডিউলেরই,
 * আর `sales` এর **উপর নির্ভর করে** — উল্টোটা নয়, নাহলে চক্র হত।
 *
 * ── ⚠️ যা এখানে ঘোষিত নেই, আর কেন ───────────────────────────────────
 * ⓘ স্পেকের §৩-এ প্রায় ত্রিশটা মেনু সারি। ⛔ কিন্তু যে রুট সত্যিই নেই
 * তার সারি ঘোষণা করা এখানে নিষেধ (`ModuleMenuTest`) — আর কারণটা ভালো:
 * মেনুতে মৃত সারি বসলে মানুষ ক্লিক করেন, কিছুই হয় না, আর কাজটা *"আছে"*
 * বলে দেখায়। ⓘ পর্দা তৈরি হওয়ার দিনেই সারিটা এখানে যোগ হবে।
 */

use App\Modules\Promotion\Dashboard\PromotionDashboard;
use App\Modules\Promotion\Models\Promotion;
use App\Modules\Promotion\Reports\PromotionReports;

return [
    'code' => 'promotion',

    'name' => [
        'en' => 'Trade Promotion',
        'bn' => 'অফার ও প্রণোদনা',
    ],

    'version' => '0.1.0',

    /*
     * সাইডবারে কোথায়।
     *
     * ⓘ ৫২ — বিক্রয়ের (৫০) ঠিক পরে, কারণ অফার বিক্রয়ের পাশে বসে আর
     * মানুষ ওটা বিক্রয়ের সাথেই খোঁজেন। ⚠️ নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     */
    'nav' => ['section' => 'business', 'order' => 52],

    /*
     * ⚠️ `sales` এখানে **নেই**, আর সেটাই মূল স্থাপত্য।
     *
     * ⓘ অফার জানে পণ্য, ক্রেতা ও মজুদ — তিনটাই মাস্টারের দিক। ⛔ বিক্রয়
     * এই মডিউলকে ডাকবে, এই মডিউল বিক্রয়কে নয়। ⚠️ উল্টোটা লিখলে নির্ভরতার
     * চক্র হত, আর তখন কোন মডিউল আগে বুট করবে তার কোনো উত্তর থাকত না।
     */
    // ⓘ accounts — উপহারের খরচ খাতায় ([[GiftIssuer]], ৪ অক্টোবর ২০২৬); accounts নিজে কারো উপর দাঁড়ায় না, তাই চক্র নেই
    'depends_on' => ['master_data', 'inventory', 'customer', 'accounts'],

    /*
     * ⭐ বিক্রয়ের কাগজে অফার — Sales এই মডিউল চেনে না, চুক্তি চেনে (২৯ সেপ্টেম্বর ২০২৬)।
     * ⓘ মডিউল বন্ধ থাকলে কোরের খালি বাস্তবায়ন বসে ([[NoSalesOffers]])।
     */
    'bindings' => [
        \App\Core\Contracts\SalesOffers::class => \App\Modules\Promotion\Services\PromotionSalesOffers::class,
        // ⭐ অর্ডারের লাইনে কয়টা ফ্রি — ১ অক্টোবর ২০২৬ ([[PromotionFreeGoods]])
        \App\Core\Contracts\FreeGoodsOffers::class => \App\Modules\Promotion\Services\PromotionFreeGoods::class,
    ],

    'menu' => [
        'dashboard' => [
            ['label' => 'promotion::dashboard.title', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'promotion'], 'permission' => 'promotion.view'],
        ],

        'master' => [
            /*
             * ⚠️ আইকনটা `star`, `tag` নয় — আর এটা সহকর্মীর ধরা।
             *
             * ⓘ `tag` নামে কোনো আইকন আঁকা নেই, আর [[x-ui.icon]] অচেনা
             * নাম পেলে **চুপ করে কিছুই আঁকে না**, ভাঙে না। ⛔ ফল হত
             * মেনুতে একটা ফাঁকা ঘর — দেখতে সাজানো, অর্থহীন।
             */
            ['label' => 'promotion::menu.promotions', 'icon' => 'star', 'route' => 'promotion.index',
                'permission' => 'promotion.view'],

            /* ⓘ রুটটা এখন সত্যিই আছে — তাই সারিটাও (§৩ Create Promotion) */
            ['label' => 'promotion::action.new', 'icon' => 'plus', 'route' => 'promotion.create',
                'permission' => 'promotion.create'],

            /* ⓘ স্পেক §৩ Gift Issue — পাওনা উপহার গুদাম থেকে বের করা */
            ['label' => 'promotion::menu.gifts', 'icon' => 'promotion', 'route' => 'promotion.gift.index',
                'permission' => 'promotion.gift'],

            /* ⓘ কুপনের কোড আর ক্রেতার পয়েন্ট — প্রতিটা নিজের চাবিতে */
            ['label' => 'promotion::coupon_screen.menu', 'icon' => 'receipt', 'route' => 'promotion.coupon.index',
                'permission' => 'promotion.coupon'],
            ['label' => 'promotion::loyalty_screen.title', 'icon' => 'wallet', 'route' => 'promotion.loyalty.index',
                'permission' => 'promotion.loyalty'],

            /* ⓘ স্পেক §১৬ Promotion Calendar */
            ['label' => 'promotion::calendar.title', 'icon' => 'calendar', 'route' => 'promotion.calendar',
                'permission' => 'promotion.view'],
        ],

        /* ⓘ স্পেক §১৭ — ১৩টা পর্দা, সবই [[PromotionReports]]-এর সংজ্ঞা, চাবি `promotion.report` */
        'reports' => [
            ['label' => 'promotion::report.title_register', 'icon' => 'list', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'register'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_active', 'icon' => 'star', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'active'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_expired', 'icon' => 'clock', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'expired'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_utilization', 'icon' => 'reports', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'utilization'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_by_customer', 'icon' => 'customer', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'by-customer'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_by_product', 'icon' => 'inventory', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'by-product'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_discounts', 'icon' => 'receipt', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'discounts'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_gifts', 'icon' => 'promotion', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'gifts'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_gift_stock', 'icon' => 'inventory', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'gift-stock'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_budgets', 'icon' => 'wallet', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'budgets'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_overrides', 'icon' => 'edit', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'overrides'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_reversals', 'icon' => 'swap', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'reversals'], 'permission' => 'promotion.report'],
            ['label' => 'promotion::report.title_cancelled_offers', 'icon' => 'alert-triangle', 'route' => 'promotion.report.show',
                'route_params' => ['slug' => 'cancelled-offers'], 'permission' => 'promotion.report'],
        ],
    ],

    /*
     * স্পেকের §১৯ Permission Matrix — আজ তেরোটা, প্রতিটা কোনো না কোনো দরজায় যাচাই হয়।
     *
     * ⚠️ `apply` আর `override` আলাদা, আর তফাতটা দামি: ⓘ প্রথমটা
     * বিক্রয়কর্মীর রোজকার কাজ (অফারটা বিলে বসানো), দ্বিতীয়টা নিয়ম
     * ভাঙা (সুবিধার পরিমাণ হাতে বদলানো)। ⛔ এক চাবি হলে যিনি অফার
     * বসাতে পারেন তিনি ছাড়ের অঙ্কও বদলাতে পারতেন।
     */
    'permissions' => [
        'promotion.view',
        'promotion.create',
        'promotion.update',
        'promotion.submit',
        'promotion.approve',
        'promotion.activate',
        'promotion.pause',
        'promotion.cancel',
        'promotion.apply',

        /*
         * ⭐ হাতে বদল — দরজাটা [[PromotionOverrideController]], কারণ বাধ্যতামূলক।
         * ⓘ দরজার সাথে একই দিনে ফিরল, আগে নয় — নিচের নোটটা দেখুন।
         */
        'promotion.override',

        /*
         * ⭐ ছাদ বসানো ও বাড়ানো — দরজাটা [[PromotionBudgetController]]।
         * ⓘ `update` থেকে আলাদা, কারণ ছাদ বাড়ানো মানে আরও টাকা দেওয়া।
         */
        'promotion.budget',

        /*
         * ⭐ উপহার বের করা — দরজাটা [[PromotionGiftController]]।
         * ⓘ গুদামের কাজ; অফার বসানোর চাবি (`apply`) মাল বের করতে দেয় না।
         */
        'promotion.gift',

        /* ⭐ §১৭-এর প্রতিবেদন দেখা — দরজাটা [[PromotionReportController]]; ছাপা ও রপ্তানিও এই চাবিতেই */
        'promotion.report',

        /*
         * ⭐ কুপনের কোড বানানো ও দেখা — দরজাটা [[PromotionCouponController]] (২৮ সেপ্টেম্বর ২০২৬)।
         * ⓘ কাউন্টারে কোড যাচাই `apply`-তেই; কোড বানানো মানে ছাড় ছড়ানো, তাই আলাদা চাবি।
         */
        'promotion.coupon',

        /* ⭐ ক্রেতার পয়েন্ট দেখা — দরজাটা [[PromotionLoyaltyController]] */
        'promotion.loyalty',

        /*
         * ⓘ এক সময় তিনটা চাবি দরজা ছাড়া ঘোষিত ছিল (`gift.issue`, `budget.view`, `report`)।
         * ⓘ বাজেট দেখা `view`-এর ভিতরে — অফারের পাতাতেই দেখায়।
         *
         * ⓘ প্রথম খসড়ায় ঘোষিত ছিল, কিন্তু কোনো দরজা ওদের যাচাই করত না।
         * ⚠️ মৃত চাবি পরের জনকে বিভ্রান্ত করে: মানুষ ভাবেন অনুমতিটা কিছু
         * পাহারা দিচ্ছে, অথচ দেয় না — ঘোষিত অথচ অচল অফার-ধরনের একই আকার।
         * ⭐ দরজাটা তৈরির দিনেই চাবিটা ফিরবে, দরজার সাথে একই কমিটে।
         */
    ],

    /*
     * নতুন ইনস্টলে কোন রোল কী পায় — শুরুর সারি, তালা নয়।
     *
     * ⛔ `approve` বা `override` কোনো ছাঁচেই নেই, আর সেটা ইচ্ছাকৃত:
     * ⓘ দুইটাই টাকার সিদ্ধান্ত, আর *"কে সই দিতে পারবেন"* ব্যবসার কথা —
     * নীরবে ধরে নেওয়ার চেয়ে খারাপ কিছু নেই।
     */
    'role_templates' => [
        'Field Sales' => ['promotion.view', 'promotion.apply'],
        'Manager' => ['promotion.view', 'promotion.create', 'promotion.update', 'promotion.report'],

        /* ⓘ সমন্বয়কের নির্দেশ (২৭ সেপ্টেম্বর): প্রতিবেদন দেখেন নিরীক্ষক আর হিসাবরক্ষকও */
        'Auditor' => ['promotion.view', 'promotion.report'],
        'Accountant' => ['promotion.view', 'promotion.report'],
    ],

    /*
     * ⛔ যে কাজগুলোতে **টাকা নড়ে**।
     *
     * ⚠️ তালিকাটা আজ খালি, আর সেটা একটা **ঘোষণা**, অনুপস্থিতি নয়:
     * ⓘ আজ এই মডিউল কেবল অফারের কাগজ রাখে — কোনো খাতায় কিছু বসে না।
     *
     * ⭐ কিন্তু উপহার দেওয়া শুরু হলেই এটা বদলাবে: উপহারের পণ্য মজুদ
     * থেকে কমে, আর তার দাম কোথাও না কোথাও বসতে হয় (§৮, §১৭ Promotion
     * Cost)। ⓘ সেদিন এখানে সারিটা যোগ হবে — আর
     * [[EveryModuleSaysWhereMoneyMovesTest]] সেটা মনে করিয়ে দেবে।
     */
    'moves_money' => [],

    /*
     * নম্বরের সিরিজ — স্পেক §২১।
     *
     * ⓘ তিনটাই ঘোষিত, যদিও আজ কেবল প্রথমটা ব্যবহার হয়। ⚠️ পরে যোগ
     * করলে চলতি প্রতিষ্ঠানে সিরিজটা নিজে থেকে তৈরি হত না, আর প্রথম
     * উপহারের দিন নম্বর বসাতে গিয়ে আটকে যেত।
     */
    'doc_types' => [
        'PROM' => 'promotion::doc.promotion',
        'GIFT' => 'promotion::doc.gift',
        'COUP' => 'promotion::doc.coupon',
    ],

    /*
     * ⚠️ নকলের পাহারা — স্পেক §২০: *"Duplicate Promotion → Warning"*।
     *
     * ⓘ একই নামে দুইটা অফার থাকলে বিক্রয়কর্মী ভুলটা বাছতেন, আর একটা
     * চলত আরেকটা পড়ে থাকত। ⭐ ইঞ্জিনটা এখানকার সাধারণ
     * [[DuplicationEngine]] — সে সতর্ক করে, আর মানুষ ঠিক করেন।
     * ⓘ ঘোষণা না থাকায় [[EveryMasterNamesItsDuplicateGuardTest]] লাল ছিল।
     */
    'duplicates' => [
        ['model' => Promotion::class, 'name' => ['name_en', 'name_bn']],
    ],

    'dashboard' => PromotionDashboard::class,

    /*
     * ⭐ অনুমোদনের ছক — Approval Centre-এর পর্দায় কাজটা দেখায় (স্পেক §১৪)।
     * ⓘ স্তরগুলো প্রতিটা কোম্পানি নিজে বসায়; ছক না থাকলে এক সইয়েই চলে।
     */
    'approvals' => ['approve' => 'promotion::approval.action'],

    /* ⓘ প্রতিবেদনের সংজ্ঞা — ReportEngine এখান থেকে নিবন্ধন করে */
    'reports' => [
        PromotionReports::class,
    ],

    'settings' => [
        [
            /*
             * ⭐ নতুন অফারের **শুরুর মান** — সিদ্ধান্ত নয়।
             *
             * ── ⓘ মালিকের সিদ্ধান্ত, ২৬ সেপ্টেম্বর ২০২৬ ─────────────
             * *"offer ghosonar somoyei tik korbe"* — অর্থাৎ প্রতিটা অফার
             * নিজে বলে সে একা চলবে, যোগ হবে, নাকি সবচেয়ে ভালোটা।
             *
             * ⛔ তাই এই সুইচটা আর কোনো সিদ্ধান্ত নেয় না। ⚠️ নিলে গতকাল
             * বানানো অফারের আচরণ আজ সুইচ বদলালেই বদলে যেত, আর যিনি
             * অফারটা বানিয়েছিলেন তিনি জানতেনও না — নীরবে।
             *
             * ⓘ সে কেবল তৈরির পর্দায় ঘরটা আগে থেকে ভরে দেয়।
             */
            'key' => 'promotion.combines_default',
            'label' => 'promotion::settings.combines_default',
            'type' => 'select',
            'options' => ['best', 'adds', 'alone'],
            'option_label' => 'promotion::combines.',
            'default' => 'best',
            'group' => 'entry',
        ],
        [
            /*
             * ⛔ অনুমোদন ছাড়া অফার চালু হবে কি না।
             *
             * ⓘ ডিফল্ট `true` — স্পেক §১৪-এর গোটা কর্মপ্রবাহটাই এই
             * ধারণার উপর। ⚠️ ছোট দোকানে যেখানে মালিক নিজেই সব করেন,
             * ওখানে সুইচটা বন্ধ করলে খসড়া থেকে সোজা চালু করা যাবে।
             */
            'key' => 'promotion.needs_approval',
            'label' => 'promotion::settings.needs_approval',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
    ],
];
