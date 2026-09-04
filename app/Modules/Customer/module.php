<?php

declare(strict_types=1);

/**
 * Customer মডিউলের পরিচয়পত্র — প্ল্যান সেকশন ১৯.২।
 *
 * Phase 2-এ এই মডিউল দিয়েই ভিত্তি প্রমাণ করা হবে (সেকশন ২.৩)। তাই এটাই
 * বাকি দশটা মডিউলের নমুনা — এখানে যা লেখা আছে, বাকিগুলোতেও ঠিক তাই থাকবে।
 */

use App\Modules\Customer\Dashboard\CustomerDashboard;
use App\Modules\Customer\Dashboard\CustomerWidgets;
use App\Modules\Customer\Imports\CustomerImporter;
use App\Modules\Customer\Models\Customer;
use App\Modules\Customer\Reports\PartyReports;

return [
    'code' => 'customer',

    'name' => [
        'en' => 'Customer',
        'bn' => 'গ্রাহক',
    ],

    'version' => '1.0.0',

    /*
     * সাইডবারে কোথায় — নির্ভরতার ক্রম নয়, মানুষের ক্রম।
     *
     * টাকা যেদিক থেকে আসে, তাই ব্যবসার প্রথম।
     *
     * দলগুলোর তালিকা আর কেন এটা `depends_on`-এর থেকে আলাদা:
     * [[ModuleDefinition::NAV_SECTIONS]].
     */
    /*
     * ── বিক্রয়ের ভেতরে, ৪ সেপ্টেম্বর ২০২৬ ─────────────────────────────
     * মালিকের নির্দেশ: *"customer modiule fontende sales er vitore
     * dukaw, bakend zemon ache temon thakbe"*। তাই রেলে গ্রাহকের নিজের
     * টাইল আর নেই — সারিগুলো বিক্রয়ের টাইলে, তার নিজের সারির পরে।
     *
     * নিচের `section` ও `order` মুছে ফেলা হয়নি ইচ্ছাকৃতভাবে: আশ্রয়দাতা
     * কোনো কারণে না থাকলে ([[MenuBuilder::settleGuestsIntoTheirHosts()]])
     * মডিউলটা নিজের টাইলে ফিরে আসে, আর তখন তার জায়গাটা জানা দরকার।
     */
    'nav' => ['section' => 'business', 'order' => 10, 'under' => 'sales'],

    // গ্রাহকের পাওনা হিসাবের খাতায় বসে, তাই accounts আগে তৈরি হতে হবে।
    /*
     * master_data যোগ হয়েছে কারণ নির্ভরতাটা সবসময়ই ছিল, শুধু লেখা ছিল না।
     *
     * গ্রাহকের পক্ষের ধরন, এলাকা ও পরিশোধের শর্ত — তিনটাই মাস্টার
     * ডাটার সারি, আর মডেল, কন্ট্রোলার ও ইমপোর্ট তিন জায়গাতেই ওগুলো
     * ডাকা হয়। অঘোষিত নির্ভরতা মানে module.php বলছে "আমি একা চলি"
     * অথচ কোড অন্য কথা বলছে; তখন কোনটা সত্যি তা কেউ জানে না।
     */
    'depends_on' => ['accounts', 'master_data'],

    /*
     * এখানে শুধু সেই স্ক্রিনগুলো, যেগুলো সত্যিই আছে।
     *
     * প্রথমে ড্যাশবোর্ড, বিবরণী, বকেয়া তালিকা, বয়সভিত্তিক ও সেটিংস — পাঁচটাই
     * লেখা ছিল, অথচ একটারও রুট তৈরি হয়নি। MenuBuilder অনুপস্থিত রুটে url
     * null দেয়, তাই মেনুতে পাঁচটা মৃত সারি বসত: ব্যবহারকারী ক্লিক করত,
     * কিছুই হত না। ওটাই সবচেয়ে খারাপ ধরনের স্টাব — কাজটা আছে বলে দেখায়।
     *
     * স্ক্রিনটা যেদিন তৈরি হবে, সেদিনই সারিটা এখানে ফিরবে। ModuleMenuTest
     * পাহারা দেয় — ঘোষিত রুট না থাকলে টেস্ট ভাঙে।
     */
    'menu' => [
        'dashboard' => [
            ['label' => 'customer::dashboard.title', 'route' => 'module.dashboard',
                'route_params' => ['module' => 'customer'], 'permission' => 'customer.view'],
        ],

        'master' => [
            ['label' => 'customer::menu.customers', 'route' => 'customer.index', 'permission' => 'customer.view'],
        ],
        'reports' => [
            ['label' => 'customer::menu.due_list', 'route' => 'customer.report.show',
                'route_params' => ['slug' => 'due-list'], 'permission' => 'customer.report'],
            ['label' => 'customer::menu.ageing', 'route' => 'customer.report.show',
                'route_params' => ['slug' => 'ageing'], 'permission' => 'customer.report'],

            // "কত পাওনা" নয়, "এ মাসে কে কত দিল" — আদায়কারীর জমার সাথে
            // মেলানোর তালিকা
            ['label' => 'customer::menu.collection', 'route' => 'customer.report.show',
                'route_params' => ['slug' => 'collection'], 'permission' => 'customer.report'],

            /*
             * কাদের লিমিট নেই — "শূন্য মানে শূন্য" সুইচের জোড়া কাগজ।
             *
             * সুইচটা টেপার আগে এই তালিকা দেখে লিমিট বসাতে হয়, নাহলে
             * যাঁদের লিমিট কেউ কোনোদিন বসায়নি তাঁরা সবাই পরদিন সকালেই
             * আটকে যাবেন — ভালো খদ্দেরসহ।
             */
            ['label' => 'customer::menu.no_limit', 'route' => 'customer.report.show',
                'route_params' => ['slug' => 'no-limit'], 'permission' => 'customer.report'],
        ],
    ],

    'permissions' => [
        'customer.view',
        'customer.create',
        'customer.update',
        'customer.delete',
        'customer.report',
        'customer.manage',
        'customer.credit_limit.override',
        'customer.portal',

        // পার্টির আচরণ — পতাকা তোলা ও নামানো, এক দায়িত্ব
        'customer.conduct.manage',
    ],

    /*
     * নতুন ইনস্টলে গ্রাহকের অনুমতি কোন রোলের (§৫)। শুরুর সারি, তালা নয়।
     * Field Sales: দোকান দেখা ও quick-create (অর্ডার নিতে)। Manager: দেখা ও রিপোর্ট।
     */
    'role_templates' => [
        'Field Sales' => ['customer.view', 'customer.create'],
        'Manager' => ['customer.view', 'customer.report'],
    ],

    // Number Series engine এগুলো থেকে prefix/counter সেটআপ তৈরি করবে।
    'doc_types' => [
        'CUS' => 'customer::doc.customer_code',
    ],

    // Drill-down engine এই মানচিত্র দিয়েই "কোন হিসাব কোথা থেকে এল" দেখায় — নিয়ম ১।
    'drill_sources' => [
        'customer' => Customer::class,
    ],

    /*
     * খতিয়ানের সারিতে গ্রাহকের নাম বসতে পারে।
     *
     * এই ঘোষণাটা ছাড়া জাবেদার লাইনে গ্রাহক বাছা যেত না — আর তখন
     * "ডিলার টাকাটা কোম্পানিকে দিয়েছে" ধরনের সমন্বয় কোথাও লেখা
     * যেত না।
     */
    'parties' => [
        'customer' => 'customer::menu.party',
    ],

    /*
     * নিজস্ব ঘর — গ্রাহকে।
     *
     * প্রতিটা ডিপোর গ্রাহকের সাথে একটু আলাদা তথ্য লাগে: রুট নম্বর,
     * দোকানের মালিকের নাম, বাজারের নাম। রাখার জায়গা না থাকলে ওগুলো
     * বিবরণের ঘরে লেখা হত, আর তখন খোঁজাও যেত না, রিপোর্টেও আসত না।
     */
    'custom_fields' => ['customer'],

    'imports' => [
        'customer' => CustomerImporter::class,
    ],

    // Report engine এগুলো boot-এ নিবন্ধন করে, তাই রিপোর্ট যোগ করতে
    // কোনো কোর ফাইলে নাম লিখতে হয় না (সেকশন ১৯.৭)।
    'reports' => [
        PartyReports::class,
    ],

    /*
     * হোম পর্দার সতর্কতা — সীমা ছাড়ানো ধার ও মোট বকেয়া।
     *
     * উইজেট, আলাদা কোনো সতর্কবার্তার ব্যবস্থা নয়: রোজ আসা বার্তা দুই
     * সপ্তাহে মানুষ পড়া বন্ধ করে দেয়, আর করণীয় সারিটা কেবল তখনই
     * চোখে পড়ে যখন সত্যিই কিছু বাকি।
     */
    'dashboard' => CustomerDashboard::class,

    'widgets' => [
        CustomerWidgets::class,
    ],

    // Control Panel-এ যে সুইচগুলো দেখাবে — নিয়ম ৭।
    'settings' => [
        [
            'key' => 'customer.require_bn_name',
            'label' => 'customer::settings.require_bn_name',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            'key' => 'customer.credit_limit_enabled',
            'label' => 'customer::settings.credit_limit_enabled',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],
        [
            /*
             * লিমিট ছাড়া বাকি নয় — মালিকের সুইচ।
             *
             * ── আজকের নিয়ম, আর কেন ওটা বদলানো হচ্ছে না নীরবে ────────
             * এতদিন `credit_limit = 0` মানে ছিল **সীমাহীন**, আর কোডে
             * কারণও লেখা ছিল: শূন্যকে "কিছুই বাকি নয়" ধরলে নতুন
             * গ্রাহকের প্রথম বিলটাই আটকে যেত।
             *
             * মালিকের সিদ্ধান্ত এর উল্টো — শূন্য মানে বাকি নয়, হয় আগে
             * অনলাইনে টাকা, নয় নগদে বিল। কিন্তু নিয়মটা চুপচাপ উল্টে
             * দিলে **যাদের লিমিট বসানোই হয়নি তাঁরা সবাই পরদিন সকালেই
             * আটকে যেতেন** — ABC-তে ১৪৮ জন, আর তাঁদের মধ্যে বড় ডিলারও
             * আছেন।
             *
             * তাই সুইচ, আর ডিফল্ট বন্ধ। মালিক আগে "কাদের লিমিট নেই"
             * তালিকা দেখে লিমিট বসাবেন, তারপর নিজের বেছে নেওয়া দিনে
             * সুইচটা টিপবেন।
             */
            'key' => 'customer.zero_limit_blocks',
            'label' => 'customer::settings.zero_limit_blocks',
            'type' => 'boolean',
            'default' => false,
            'group' => 'entry',
        ],
        [
            'key' => 'customer.block_over_limit',
            'label' => 'customer::settings.block_over_limit',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],

        /*
         * সীমা ছাড়ানোর সতর্কতা — হোম পর্দার করণীয় সারিতে।
         *
         * `block_over_limit` আর এটা দুইটা আলাদা প্রশ্ন: প্রথমটা বলে
         * সীমা ছাড়ানো বিল আটকাবে কি না, এটা বলে **যাঁরা ইতিমধ্যেই
         * ছাড়িয়ে গেছেন** তাঁদের কথা মালিককে বলা হবে কি না। বিল আটকানো
         * বন্ধ রেখেও কারা ছাড়িয়েছেন তা জানতে চাওয়া স্বাভাবিক।
         */
        [
            'key' => 'customer.alert_over_limit',
            'label' => 'customer::settings.alert_over_limit',
            'type' => 'boolean',
            'default' => true,
            'group' => 'entry',
        ],

        /*
         * মোট বকেয়ার সীমা — ০ মানে বন্ধ।
         *
         * শূন্যকে সীমা ধরলে সুইচটা চালু করা মাত্রই রোজ সতর্কতা আসত,
         * কারণ বকেয়া সবসময়ই শূন্যের বেশি।
         */
        [
            'key' => 'customer.alert_receivable_over',
            'label' => 'customer::settings.alert_receivable_over',
            'type' => 'integer',
            'default' => 0,
            'group' => 'entry',
        ],
        [
            'key' => 'customer.show_photo_on_print',
            'label' => 'customer::settings.show_photo_on_print',
            'type' => 'boolean',
            'default' => false,
            'group' => 'print',
        ],
    ],
];
