<?php

declare(strict_types=1);

use App\Modules\Restaurant\Dashboard\RestaurantDashboard;
use App\Modules\Restaurant\Listeners\SendTheOrderToTheKitchen;
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
    ],

    'menu' => [
        'dashboard' => [
            ['label' => 'restaurant::dashboard.title', 'route' => 'module.dashboard',
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
            ['label' => 'restaurant::menu.kitchen_board', 'route' => 'restaurant.kitchen.index',
                'permission' => 'restaurant.kitchen.view'],
            ['label' => 'restaurant::menu.kot', 'route' => 'restaurant.kitchen.tickets',
                'permission' => 'restaurant.kitchen.view'],

            ['label' => 'restaurant::menu.orders', 'route' => 'restaurant.order.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.service', 'route' => 'restaurant.service.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.delivery', 'route' => 'restaurant.delivery.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.reservation', 'route' => 'restaurant.reservation.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.payments', 'route' => 'restaurant.payment.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.wastage', 'route' => 'restaurant.wastage.index',
                'permission' => 'restaurant.view', 'planned' => true],
        ],

        'master' => [
            ['label' => 'restaurant::menu.tables', 'route' => 'restaurant.table.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.menu_cards', 'route' => 'restaurant.card.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.recipes', 'route' => 'restaurant.recipe.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.combos', 'route' => 'restaurant.combo.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.guests', 'route' => 'restaurant.guest.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.equipment', 'route' => 'restaurant.equipment.index',
                'permission' => 'restaurant.view', 'planned' => true],
        ],

        'reports' => [
            ['label' => 'restaurant::menu.food_costing', 'route' => 'restaurant.costing.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.analytics', 'route' => 'restaurant.analytics.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.hygiene', 'route' => 'restaurant.hygiene.index',
                'permission' => 'restaurant.view', 'planned' => true],
        ],

        'settings' => [
            ['label' => 'restaurant::menu.online_orders', 'route' => 'restaurant.online.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.loyalty', 'route' => 'restaurant.loyalty.index',
                'permission' => 'restaurant.view', 'planned' => true],
            ['label' => 'restaurant::menu.settings', 'route' => 'restaurant.settings',
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
    'listeners' => [
        InvoiceConfirmed::class => [SendTheOrderToTheKitchen::class],
    ],
];
