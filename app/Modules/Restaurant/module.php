<?php

declare(strict_types=1);

use App\Core\Contracts\RecipeBook;
use App\Modules\Restaurant\Dashboard\RestaurantDashboard;
use App\Modules\Restaurant\Listeners\SendTheOrderToTheKitchen;
use App\Modules\Restaurant\Reports\RestaurantReports;
use App\Modules\Restaurant\Services\RecipeBookAdapter;
use App\Modules\Sales\Events\InvoiceConfirmed;

/*
 * রেস্টুরেন্ট ব্যবস্থাপনা — শিল্প-নির্দিষ্ট স্তর।
 *
 * ── কেন এটা আলাদা একটা মডিউল, মজুদের ভেতরে নয় ────────────────────────
 * রান্নাঘরের পর্দা প্রথমে মজুদের ভেতরে ছিল, আর সেটা কাজও করত। কিন্তু
 * রেস্টুরেন্টের কাজ কেবল রান্না নয়: টেবিল, অর্ডার, KOT, পরিবেশন,
 * ডেলিভারি, রিজার্ভেশন, অতিথি, অপচয়, খাবারের খরচ। ওগুলো মজুদের ভেতরে
 * রাখলে মজুদ মডিউলটা আর মজুদের থাকত না।
 *
 * ── এটা একটা **সাজানোর স্তর**, নতুন কোনো কোর নয় ──────────────────────
 * মালিকের নকশা, ২ সেপ্টেম্বর ২০২৬:
 *
 *     গ্রাহক অর্ডার → KOT → রান্নাঘর → খাবার তৈরি → বিলিং
 *
 * ওই কাজের ধারাটা এখানে। কিন্তু **সংখ্যাগুলো এখানে থাকে না**:
 *
 *     ২ কেজি মুরগি খরচ হলো   → মজুদে যায়
 *     ৳৫,০০০ বিক্রি হলো      → বিক্রয় + অর্থ + হিসাবে যায়
 *     ৳৫,০০০ নগদ এলো        → অর্থ + হিসাবে যায়
 *
 * অর্থাৎ রেস্টুরেন্ট **নির্দেশ দেয়, খাতা রাখে না**। খাতা রাখে কোর
 * মডিউলগুলো, আর তারা এই মডিউলের অস্তিত্ব জানেও না।
 *
 * ── কেন এই বিন্যাসটা ভবিষ্যতের জন্য ──────────────────────────────────
 * একই ছকে পরে হোটেল, হাসপাতাল, স্কুল, কারখানা, লজিস্টিক বসানো যাবে —
 * প্রতিটা নিজের কাজের ধারা নিয়ে, আর **কোর মডিউলের একটা লাইনও না
 * ছুঁয়ে**। শিল্প-নির্দিষ্ট জিনিস কোরে ঢুকলে প্রতিটা নতুন শিল্পে কোর
 * ভাঙত।
 *
 * ── কেন `depends_on`-এ বিক্রয় **আর** মজুদ ────────────────────────────
 * সাজানোর স্তরকে দুইটাকেই চিনতে হয়: অর্ডার আসে বিক্রয় থেকে, উপকরণ
 * যায় মজুদে। তীরটা এদিকেই সত্যি — বিক্রয় বা মজুদ রেস্টুরেন্টকে চেনে
 * না, আর চেনার দরকারও নেই। [[BoundariesTest]] একবার ঠিক এই ভুলটাই
 * ধরেছিল, যখন রান্নাঘরের শ্রোতা মজুদে বসানো হয়েছিল।
 */
return [
    'code' => 'restaurant',

    'name' => [
        'en' => 'Restaurant',
        'bn' => 'রেস্টুরেন্ট',
    ],

    'version' => '1.0.0',

    'depends_on' => ['master_data', 'accounts', 'inventory', 'sales'],

    'nav' => ['section' => 'business', 'order' => 60],

    'dashboard' => RestaurantDashboard::class,

    /*
     * ── কেন এত কম অনুমতি, অথচ কুড়িটা মেনু ───────────────────────────
     * অনুমতি সেই কাজের জন্যই বসে যেটা **সত্যিই আছে**। বিশটা নাম দেখে
     * বিশটা অনুমতি বানালে `PermissionService` কুড়িটা চাবি বিলি করত
     * এমন পর্দার জন্য যেগুলো নেই — আর সেগুলো একদিন ভুলে যাওয়া হত
     * ঠিক যখন পর্দাটা সত্যিই তৈরি হয়।
     *
     * ⚠️ **তবু পরিকল্পিত সারিকেও তিনটা চাবিই ঘোষণা করতে হয়** —
     * `label`, `route`, `permission`। `planned` কেবল সারিটা **মেনুতে
     * দেখানো** বন্ধ করে; ঘোষণা মাফ করে না, আর `ModuleDefinition`
     * বুট-টাইমেই তিনটা দাবি করে।
     *
     * এখানে সবগুলো `restaurant.view`-এর পেছনে, আর রুটের নামগুলো
     * ভবিষ্যতের — ওগুলো এখনো নেই, আর **থাকা উচিতও নয়**:
     * `ModuleMenuTest` উল্টো দিকেও পাহারা দেয় (planned অথচ রুট আছে →
     * লাল), যাতে পর্দাটা তৈরি হওয়ার দিন পতাকাটা তুলতে ভুল না হয়।
     */
    'permissions' => [
        'restaurant.view',
        'restaurant.kitchen.view',
        'restaurant.kitchen.manage',

        /*
         * রান্না ও রিপোর্টের চাবি — মজুদ থেকে পর্দা দুইটা আসার সাথে,
         * ১৫ সেপ্টেম্বর ২০২৬।
         *
         * ⭐ চাবিগুলো **রেস্তোরাঁর নিজের**, মজুদের ধার করা নয় — আর
         * কারণটা মাপা: `inventory.production.*` কোনো রোল-টেমপ্লেটে ছিল
         * না, কেবল মালিকের কাছে (`Permission::all()`)। অর্থাৎ নতুন
         * চাবিতে যাওয়ায় আজ কেউ কিছু হারাচ্ছে না।
         *
         * ⓘ রান্নাঘরের সারিগুলোর মন্তব্যে লেখা "সঠিক পথ"টা এটাই ছিল:
         * আগে নতুন চাবি, তারপর রুট। এখানে দুইটাই একসাথে হলো, কারণ
         * হারানোর মতো কেউ ছিল না।
         */
        'restaurant.production.view',
        'restaurant.production.create',
        'restaurant.production.confirm',
        'restaurant.report',

        /*
         * রেসিপির চাবি — পর্দাটা আসার সাথে, ১৫ সেপ্টেম্বর ২০২৬।
         *
         * ⚠️ পুরনো `inventory.recipe.*` চাবিগুলো **কোনো রোল-টেমপ্লেটে
         * ছিল না** (মেপে দেখা) — কেবল মালিকের কাছে। তাই নতুন চাবিতে
         * যাওয়ায় আজ কেউ কিছু হারাচ্ছে না, ঠিক রান্নার মতোই।
         */
        'restaurant.recipe.view',
        'restaurant.recipe.create',
        'restaurant.recipe.update',
        'restaurant.recipe.delete',
    ],

    /*
     * ⚠️ `restaurant.report` ম্যানেজারকে দেওয়া — আর এটা না দিলে একটা
     * নীরব ক্ষতি হত।
     *
     * খাদ্য-খরচের রিপোর্ট আগে `inventory.report` চাইত, আর ওটা মজুদের
     * `Manager` টেমপ্লেটে আছে। ⓘ চাবি বদলে টেমপ্লেট না দিলে আজ যে
     * ম্যানেজার রিপোর্টটা দেখেন তিনি কাল সারিটাই দেখতেন না — আর কেউ
     * বলত না কেন।
     */
    'role_templates' => [
        'Manager' => ['restaurant.view', 'restaurant.report'],
        'Kitchen' => [
            'restaurant.view',
            'restaurant.kitchen.view',
            'restaurant.kitchen.manage',

            /*
             * ⭐ রেসিপি লেখা ও বদলানো রাঁধুনির কাজ — মালিকের নয়।
             *
             * ── কেন `view`-এর পাশে `create` ও `update`-ও ────────────
             * এই রিপোতেই কারণটা আগে লেখা আছে (মজুদের `Warehouse`
             * টেমপ্লেটে, "মাল বুঝে নেওয়া"র চাবি নিয়ে): চাবিটা কেবল
             * মালিকের কাছে থাকলে রাঁধুনি রোজ সকালে মালিককে ডেকে
             * আনতেন, আর নিয়মটা এক সপ্তাহে "অসুবিধা" হয়ে যেত।
             *
             * ⛔ `delete` **ইচ্ছাকৃতভাবে বাদ**, আর সেটাই এখানে মূল
             * সিদ্ধান্ত। একটা রেসিপি মুছে ফেলা মানে ঐ খাবারের প্রতিটা
             * ভবিষ্যৎ বিক্রিতে উপকরণ কাটা বন্ধ হয়ে যাওয়া — গুদামের
             * সংখ্যা নীরবে ভুল হতে থাকত। ⚠️ ভুল রেসিপি শোধরানোর পথ
             * `update`, আর আর দরকার না হলে `activate` দিয়ে নিষ্ক্রিয়।
             */
            'restaurant.recipe.view',
            'restaurant.recipe.create',
            'restaurant.recipe.update',

            'restaurant.production.view',
            'restaurant.production.create',
            'restaurant.production.confirm',
        ],
    ],

    'menu' => [
        'dashboard' => [
            ['label' => 'restaurant::dashboard.title', 'icon' => 'dashboard', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'restaurant'], 'permission' => 'restaurant.view'],
        ],

        /*
         * ── যা আজ সত্যিই চলে ─────────────────────────────────────────
         * রান্নাঘরের বোর্ড আর টিকিটের পর্দা — দুইটাই মজুদ থেকে এখানে
         * এসেছে, কোড অপরিবর্তিত। বাকি সব সারি `planned`।
         */
        'transactions' => [
            /*
             * ⚠️ চাবিটা `inventory.recipe.view`, `restaurant.kitchen.view` নয় —
             * আর এটা ইচ্ছাকৃত, ভুল নয়।
             *
             * রান্নাঘরের কন্ট্রোলারটা মজুদ থেকে এখানে আনা হয়েছে, আর তার
             * `can:` অপরিবর্তিত রয়ে গেছে (`KitchenBoardController:61`)। মেনু
             * চাইছিল অন্য চাবি, তাই যাঁর মেনুর চাবি আছে অথচ রুটেরটা নেই
             * তিনি **সারিটা দেখতেন আর ক্লিক করলে ৪০৩ পেতেন** — রোজ, নিজের
             * কাজের পর্দায়।
             *
             * ── কেন মেনু বদলানো, রুট নয় ─────────────────────────────────
             * রুটে `restaurant.kitchen.view` বসালে যাঁদের আজ
             * `inventory.recipe.view` আছে **তাঁরা চলতি পর্দাটা হারাতেন**।
             * মেনু বদলালে কেবল একটা অকেজো সারি যায়। সন্দেহে কম ক্ষতির
             * দিকটাই নেওয়া হলো।
             *
             * ⓘ শেষ অবস্থাটা আদর্শ নয় — রেস্তোরাঁর পর্দা মজুদের অনুমতি
             * চাইছে, অর্থাৎ রেস্তোরাঁ-মাত্র কোম্পানিকেও মজুদের চাবি দিতে
             * হবে। ঠিক করার পথ: আগে যাঁদের `inventory.recipe.view` আছে
             * তাঁদের `restaurant.kitchen.view` দেওয়া, **তারপর** রুট বদলানো।
             * ওটা মালিকের সিদ্ধান্ত, তাই আজ নয়।
             */
            ['label' => 'restaurant::menu.kitchen_board', 'icon' => 'grid', 'route' => 'restaurant.kitchen.index',
                'permission' => 'restaurant.kitchen.view'],
            ['label' => 'restaurant::menu.kot', 'icon' => 'printer', 'route' => 'restaurant.kitchen.tickets',
                'permission' => 'restaurant.kitchen.view'],

            /*
             * রান্না — মজুদ থেকে এখানে আনা, ১৫ সেপ্টেম্বর ২০২৬।
             *
             * মালিক ছবিতে দাগিয়ে বলেছেন এটা রেস্তোরাঁর পর্দা। ⭐ কথাটা
             * ঠিক: রান্না একটা ঘটনা যা রোজ সকালে ঘটে, আর মজুদ কেবল বলে
             * কী ঢুকল আর কী বেরোল।
             *
             * ⭐ চাবিটা রেস্তোরাঁর নিজের, আর [[ProductionPolicy]]-ও একই
             * চাবি চায় — তাই মেনু যা দেখায় রুটও তাই মানে। ⓘ রান্নাঘরের
             * সারিগুলোয় এই দুইটা এখনো আলাদা (ঐ মন্তব্যে কারণ লেখা);
             * এখানে মেলানো গেছে কারণ পুরনো চাবি কারও কাছে ছিল না।
             */
            ['label' => 'restaurant::menu.cooking', 'icon' => 'refresh', 'route' => 'restaurant.production.index',
                'permission' => 'restaurant.production.view'],

            ['label' => 'restaurant::menu.orders', 'icon' => 'book', 'route' => 'restaurant.order.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.service', 'icon' => 'people', 'route' => 'restaurant.service.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.delivery', 'icon' => 'share', 'route' => 'restaurant.delivery.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.reservation', 'icon' => 'calendar', 'route' => 'restaurant.reservation.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.payments', 'icon' => 'cash', 'route' => 'restaurant.payment.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.wastage', 'icon' => 'trash', 'route' => 'restaurant.wastage.index',
                'permission' => 'restaurant.view', 'planned' => true],
        ],

        'master' => [
            ['label' => 'restaurant::menu.tables', 'icon' => 'columns', 'route' => 'restaurant.table.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.menu_cards', 'icon' => 'list', 'route' => 'restaurant.card.index',
                'permission' => 'restaurant.view', 'planned' => true],
            /*
             * ⭐ রেসিপি — `planned` ছিল, ১৫ সেপ্টেম্বর ২০২৬-এ আসল হলো।
             *
             * মালিকের সিদ্ধান্ত: *"রেসিপি রান্নাঘরে যাবে।"* ⓘ সারিটা ও
             * ঠিকানাটা এখানে আগে থেকেই ঘোষিত ছিল — কেবল পর্দাটা মজুদে
             * চলত। এখন দুইটাই এক জায়গায়।
             */
            ['label' => 'restaurant::menu.recipes', 'icon' => 'book', 'route' => 'restaurant.recipe.index',
                'permission' => 'restaurant.recipe.view'],
            ['label' => 'restaurant::menu.combos', 'icon' => 'plus', 'route' => 'restaurant.combo.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.guests', 'icon' => 'customer', 'route' => 'restaurant.guest.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.equipment', 'icon' => 'settings', 'route' => 'restaurant.equipment.index',
                'permission' => 'restaurant.view', 'planned' => true],
        ],

        'reports' => [
            /*
             * ⭐ খাদ্য-খরচ — `food_costing` নামে একটা `planned` সারি এখানে
             * ছিল, আর আসল পর্দাটা মজুদে চলত। মালিকের দাগানো অনুযায়ী
             * (১৫ সেপ্টেম্বর ২০২৬) আসলটাই এখানে এল, আর খালি প্রতিশ্রুতিটা
             * সরে গেল — দুইটা সারি রাখলে একটা কাজ করত, অন্যটা ৪০৪ দিত।
             */
            ['label' => 'restaurant::menu.food_cost', 'icon' => 'wallet', 'route' => 'restaurant.report.show',
                'route_params' => ['slug' => 'food-cost'], 'permission' => 'restaurant.report'],
            ['label' => 'restaurant::menu.analytics', 'icon' => 'reports', 'route' => 'restaurant.analytics.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.hygiene', 'icon' => 'check-circle', 'route' => 'restaurant.hygiene.index',
                'permission' => 'restaurant.view', 'planned' => true],
        ],

        'settings' => [
            ['label' => 'restaurant::menu.online_orders', 'icon' => 'globe', 'route' => 'restaurant.online.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.loyalty', 'icon' => 'star', 'route' => 'restaurant.loyalty.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.settings', 'icon' => 'settings', 'route' => 'restaurant.settings',
                'permission' => 'restaurant.view', 'planned' => true],
        ],
    ],

    /*
     * বিক্রয়ের ঘটনা শোনা — বিল নিশ্চিত হলে রান্নাঘরে টিকিট।
     *
     * ── কেন এটা এখন এখানে, বিক্রয়ে নয় ──────────────────────────────
     * শ্রোতাটা বিক্রয়ে ছিল, আর সেটা তখন **ঠিকই ছিল**: রান্নাঘর ছিল
     * মজুদের ভেতরে, আর বিক্রয় মজুদকে চেনে। এখন রান্নাঘর এখানে, আর
     * এই মডিউল বিক্রয়কে চেনে — তাই তীরটা আবারও সত্যি দিকেই।
     *
     * ⚠️ উল্টোটা করা যেত না: বিক্রয়ে রেখে রেস্টুরেন্টের সার্ভিস ডাকলে
     * বিক্রয়কে রেস্টুরেন্টের উপর নির্ভর করতে হত, আর তখন **রেস্টুরেন্ট
     * বন্ধ করা কোম্পানিতেও বিক্রয় চলত না**।
     */
    /*
     * রান্নার কাগজের নম্বর — `CKG`, মজুদ থেকে হুবহু আনা।
     *
     * ⚠️ চাবিটা `CKG`-ই রাখা হয়েছে, বদলানো হয়নি: `number_series` ও
     * `issued_numbers`-এ চলতি সারিগুলো এই স্ট্রিং ধরে বসে আছে। নতুন
     * চাবি দিলে সিরিজটা শূন্য থেকে শুরু হত আর পুরনো নম্বরগুলো অনাথ হত।
     */
    /*
     * রেসিপির বই — কোরের চুক্তি, রেস্তোরাঁর বাস্তবায়ন।
     *
     * ── ⭐ কেন এই সারিটা মজুদ থেকে এখানে এল ──────────────────────────
     * বিক্রয়ের বিলের সেবা রেসিপি পড়ে, কিন্তু বিক্রয় রেস্তোরাঁর উপর
     * দাঁড়াতে পারে না। ⓘ তাই মাঝে কোরের চুক্তি, আর বাস্তবায়নটা যে
     * মডিউলের রেসিপি তারই।
     *
     * ⛔ কেউ এটা না বাঁধলে বিক্রয় কোরের শূন্য-বাস্তবায়ন পেত, আর
     * **প্রতিটা মেড-টু-অর্ডার খাবারের বিক্রয়যোগ্য শূন্য** হয়ে যেত।
     * ⚠️ পরীক্ষায় ঠিক তাই ধরা পড়েছিল — ২৬টার ৮টা লাল, সবগুলোরই
     * বার্তা *"বিক্রয়যোগ্য আছে ০"*।
     */
    'bindings' => [
        RecipeBook::class => RecipeBookAdapter::class,
    ],

    'doc_types' => [
        'CKG' => 'restaurant::doc.production',
    ],

    'reports' => [
        RestaurantReports::class,
    ],

    'listeners' => [
        InvoiceConfirmed::class => [SendTheOrderToTheKitchen::class],
    ],
];
